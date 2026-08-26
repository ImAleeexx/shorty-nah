<?php

declare(strict_types=1);

namespace App\Setup;

use App\Settings\SettingsRegistry;
use App\Settings\SettingsStore;

/**
 * Which wizard steps this instance has already accepted.
 *
 * Progress lives in the settings store rather than a session, so closing the
 * browser and coming back resumes where the operator stopped instead of
 * starting over. Completion is recorded explicitly rather than inferred from the
 * data a step wrote: branding and analytics have defaults, so "has a value"
 * cannot distinguish a submitted step from an untouched one.
 */
final class SetupProgress
{
    public function __construct(private readonly SettingsStore $settings) {}

    /**
     * @return list<SetupStep>
     */
    public function completed(): array
    {
        $stored = $this->settings->string(SettingsRegistry::SETUP_COMPLETED_STEPS);

        if ($stored === null || $stored === '') {
            return [];
        }

        $steps = [];

        foreach (explode(',', $stored) as $value) {
            $step = SetupStep::tryFrom(trim($value));

            if ($step instanceof SetupStep) {
                $steps[] = $step;
            }
        }

        return $steps;
    }

    public function isComplete(SetupStep $step): bool
    {
        return in_array($step, $this->completed(), true);
    }

    public function complete(SetupStep $step): void
    {
        if ($this->isComplete($step)) {
            return;
        }

        $completed = $this->completed();
        $completed[] = $step;

        // Written in declaration order so the stored value reads the way the
        // wizard runs, whatever order the steps were accepted in.
        $ordered = array_filter(
            SetupStep::ordered(),
            static fn (SetupStep $candidate): bool => in_array($candidate, $completed, true),
        );

        $this->settings->set(
            SettingsRegistry::SETUP_COMPLETED_STEPS,
            implode(',', array_map(static fn (SetupStep $s): string => $s->value, $ordered)),
        );
    }

    /**
     * The step the wizard should open on. Null once every step is done.
     */
    public function next(): ?SetupStep
    {
        foreach (SetupStep::ordered() as $step) {
            if (! $this->isComplete($step)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The first predecessor of the given step that has not been completed.
     */
    public function firstOutstandingPredecessor(SetupStep $step): ?SetupStep
    {
        foreach ($step->predecessors() as $predecessor) {
            if (! $this->isComplete($predecessor)) {
                return $predecessor;
            }
        }

        return null;
    }

    public function reset(): void
    {
        $this->settings->forget(SettingsRegistry::SETUP_COMPLETED_STEPS);
    }
}

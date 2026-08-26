<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 */
final class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [];

    public static function normalise(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    protected static function newFactory(): TagFactory
    {
        return TagFactory::new();
    }

    /**
     * @return BelongsToMany<Link, $this>
     */
    public function links(): BelongsToMany
    {
        return $this->belongsToMany(Link::class);
    }
}

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { FormError } from './form-error';

describe('FormError', () => {
  it('renders nothing when there is no failure', () => {
    const { container } = render(<FormError failure={null} />);

    expect(container).toBeEmptyDOMElement();
  });

  // The case that shipped broken: a refusal naming no field. Rendering only
  // per-field errors made this one invisible.
  it('renders a refusal that names no field', () => {
    render(
      <FormError
        failure={{ ok: false, status: 423, message: 'Confirm your password to continue.', errors: {} }}
      />,
    );

    expect(screen.getByRole('alert')).toHaveTextContent('Confirm your password to continue.');
  });

  // Field errors already sit against their own inputs; repeating them at the top
  // says the same thing twice.
  it('stays silent when every message is attached to a field', () => {
    const { container } = render(
      <FormError
        failure={{
          ok: false,
          status: 422,
          message: 'The given data was invalid.',
          errors: { accent: ['An accent must be an OKLCH colour.'] },
        }}
      />,
    );

    expect(container).toBeEmptyDOMElement();
  });
});

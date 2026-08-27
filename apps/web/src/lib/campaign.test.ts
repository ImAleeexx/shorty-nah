import { describe, expect, it } from 'vitest';

import { applyCampaign, hasCampaign, parsePresets, readCampaign } from './campaign';

describe('campaign parameters', () => {
  it('writes parameters onto a destination carrying none', () => {
    const result = applyCampaign('https://example.com/page', {
      utm_source: 'newsletter',
      utm_medium: 'email',
    });

    const url = new URL(result);

    expect(url.searchParams.get('utm_source')).toBe('newsletter');
    expect(url.searchParams.get('utm_medium')).toBe('email');
  });

  // The destination's own query string is none of this feature's business.
  it("preserves a destination's own query parameters", () => {
    const result = applyCampaign('https://example.com/page?ref=abc&page=2', {
      utm_source: 'newsletter',
    });

    const url = new URL(result);

    expect(url.searchParams.get('ref')).toBe('abc');
    expect(url.searchParams.get('page')).toBe('2');
    expect(url.searchParams.get('utm_source')).toBe('newsletter');
  });

  it('replaces a parameter rather than appending a second one', () => {
    const result = applyCampaign('https://example.com/page?utm_source=old', {
      utm_source: 'new',
    });

    expect(new URL(result).searchParams.getAll('utm_source')).toEqual(['new']);
  });

  it('removes a parameter when its value is cleared', () => {
    const result = applyCampaign('https://example.com/page?utm_source=old&ref=abc', {
      utm_source: '',
    });

    const url = new URL(result);

    expect(url.searchParams.has('utm_source')).toBe(false);
    expect(url.searchParams.get('ref')).toBe('abc');
  });

  it('treats a whitespace-only value as cleared', () => {
    const result = applyCampaign('https://example.com/page?utm_term=old', { utm_term: '   ' });

    expect(new URL(result).searchParams.has('utm_term')).toBe(false);
  });

  it('reads existing parameters back out for editing', () => {
    const values = readCampaign('https://example.com/p?utm_source=x&utm_campaign=spring&ref=1');

    expect(values).toEqual({ utm_source: 'x', utm_campaign: 'spring' });
  });

  it('reports whether a destination carries any campaign parameter', () => {
    expect(hasCampaign('https://example.com/p?utm_source=x')).toBe(true);
    expect(hasCampaign('https://example.com/p?ref=1')).toBe(false);
  });

  // A half-typed destination must not throw inside a form.
  it('leaves an unparseable destination alone', () => {
    expect(applyCampaign('not a url', { utm_source: 'x' })).toBe('not a url');
    expect(readCampaign('not a url')).toEqual({});
  });

  describe('presets', () => {
    it('reads a list of named presets', () => {
      const presets = parsePresets(
        JSON.stringify([{ name: 'Newsletter', values: { utm_source: 'newsletter' } }]),
      );

      expect(presets).toEqual([{ name: 'Newsletter', values: { utm_source: 'newsletter' } }]);
    });

    // An operator who pasted something wrong into settings should still be able
    // to create a link.
    it('yields nothing rather than throwing on malformed settings', () => {
      expect(parsePresets('{oops')).toEqual([]);
      expect(parsePresets('{"not":"a list"}')).toEqual([]);
      expect(parsePresets(null)).toEqual([]);
      expect(parsePresets('')).toEqual([]);
    });

    it('skips entries without a name and ignores unknown keys', () => {
      const presets = parsePresets(
        JSON.stringify([
          { values: { utm_source: 'x' } },
          { name: 'Good', values: { utm_source: 'y', nonsense: 'z' } },
        ]),
      );

      expect(presets).toEqual([{ name: 'Good', values: { utm_source: 'y' } }]);
    });
  });
});

import { CreateWorkspace } from './create-workspace';

describe('CreateWorkspace.slugify', () => {
  it('derives a lowercase hyphenated slug', () => {
    expect(CreateWorkspace.slugify('Northgate Ops')).toBe('northgate-ops');
    expect(CreateWorkspace.slugify('  Acme & Co!! ')).toBe('acme-co');
    expect(CreateWorkspace.slugify('Ünïcode Näme')).toBe('unicode-name');
  });

  it('caps at 80 characters', () => {
    expect(CreateWorkspace.slugify('a'.repeat(100)).length).toBe(80);
  });
});

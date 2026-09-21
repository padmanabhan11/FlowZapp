import { InviteTeam } from './invite-team';

describe('InviteTeam.emailOk', () => {
  it('accepts plain addresses and rejects junk', () => {
    expect(InviteTeam.emailOk('ana@example.test')).toBe(true);
    expect(InviteTeam.emailOk(' ana@example.test ')).toBe(true);
    expect(InviteTeam.emailOk('not-an-email')).toBe(false);
    expect(InviteTeam.emailOk('a@b')).toBe(false);
  });
});

// Written at build time by scripts/write-env.mjs from the build environment
// (SENTRY_DSN_WEB, SENTRY_RELEASE, SENTRY_ENVIRONMENT). This committed copy
// is the local-development default: error tracking off.
export const BUILD_ENV = {
  sentryDsn: '',
  release: 'dev',
  environment: 'local',
};

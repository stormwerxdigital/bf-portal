/*
 * Playwright for every browser test, found one way.
 *
 * Playwright is installed globally rather than beside the plugin, and the
 * plugin ships no node_modules, so it is resolved through the global root.
 * execSync goes through a shell, so `npm` is found on Windows too (npm.cmd).
 * The Cowork container keeps its own Chromium at /opt/pw-browsers; anywhere
 * else Playwright's own download is used.
 */
import { execSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { existsSync } from 'node:fs';

const require_ = createRequire(import.meta.url);
export const { chromium } = require_(execSync('npm root -g').toString().trim() + '/playwright');

const container = '/opt/pw-browsers/chromium';
export const launchOptions = existsSync(container) ? { executablePath: container } : {};

import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { PassThrough } from 'node:stream';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { brotliCompressSync, deflateSync, gzipSync } from 'node:zlib';

import { __test, decideAttempts, githubRequest, isUnsafeIp, manageIssue, parseManagementPayload, preflight, runVerifyMode, validateConfig, verify } from './verify.mjs';

const LIMITS = Object.freeze({ max_attempts: 3, consecutive_successes: 2, incident_repeat_count: 2, max_requests_per_attempt: 10, max_total_requests: 30, request_concurrency: 2, request_timeout_ms: 10000, phase_timeout_ms: 360000, evidence_reserve_ms: 30000, retry_delay_ms: 100, max_redirect_hops: 3, max_response_headers: 64, max_header_bytes: 32768, max_body_bytes: 1048576, max_artifact_bytes: 262144 });

function rawConfig(options = {}) {
  return {
    schema_version: 2,
    provenance: { mode: options.provenanceMode ?? 'claims_only', release_asset_name: 'plugin-{version}.zip' },
    environments: { preview: {
      targets: { primary: { base_url: options.primary ?? 'https://public.example.com/base/', basic_auth: options.basicAuth ?? false }, cdn: { base_url: 'https://cdn.example.com/assets/', basic_auth: false } },
      allowed_redirects: options.redirects ?? [],
      checks: options.checks ?? [{ id: 'home', target: 'primary', type: 'page', path: '/', method: 'GET', expected_status: 200, required: true, fatal_signatures: true, required_text: ['Ready'], forbidden_text: ['Never'] }],
      version_sources: options.versionSources ?? [
        { id: 'page', target: 'primary', type: 'text_marker', path: '/', required: true, prefix: 'Version: ', suffix: '\n' },
        { id: 'cdn', target: 'cdn', type: 'response_header', path: '/plugin.css', required: true, header_name: 'x-release-version' },
      ],
    } },
    limits: { ...LIMITS, ...options.limits },
  };
}

function validated(options = {}) { return { ...validateConfig(rawConfig(options), 'preview'), configDigest: 'a'.repeat(64) }; }
const provenanceClaims = Object.freeze({ mode: 'claims_only', status: 'owner_assertion', github_release_id: null, release_tag_commit_sha: null, asset: null });
function encode(value) { return Buffer.from(JSON.stringify(value), 'utf8').toString('base64url'); }

const baseEnv = Object.freeze({ TARGET_ENVIRONMENT: 'preview', EXPECTED_VERSION: '1.2.3', DEPLOYMENT_COMMIT_SHA: '1'.repeat(40), RELEASE_ID: 'v1.2.3', CLAIM_SOURCE: 'manual_operator', VERIFIER_COMMIT_SHA: '2'.repeat(40), WORKFLOW_SHA: '3'.repeat(40), GITHUB_REPOSITORY: 'owner/plugin', GITHUB_RUN_ID: '100', GITHUB_RUN_ATTEMPT: '1', GITHUB_EVENT_NAME: 'workflow_dispatch', INVOCATION_KIND: 'dispatch', MANUAL_INSTALL_CONFIRMED: 'true', DEPLOYMENT_CONCLUSION: '', PROVENANCE_PAYLOAD: encode(provenanceClaims) });

async function withEnv(values, callback) {
  const saved = new Map(Object.keys(values).map((key) => [key, process.env[key]]));
  for (const [key, value] of Object.entries(values)) value === undefined || value === null ? delete process.env[key] : process.env[key] = String(value);
  try { return await callback(); } finally { for (const [key, value] of saved) value === undefined ? delete process.env[key] : process.env[key] = value; }
}

function response(statusCode = 200, body = '', headers = {}) { return { statusCode, body, headers, headerCounts: new Map(Object.keys(headers).map((name) => [name.toLowerCase(), 1])) }; }
function successTarget(url, method) { return url.hostname === 'cdn.example.com' ? Promise.resolve(response(200, method === 'HEAD' ? '' : 'asset', { 'x-release-version': '1.2.3' })) : Promise.resolve(response(200, 'Ready\nVersion: 1.2.3\n', { 'content-type': 'text/html' })); }

function management(overrides = {}) {
  return { schema_version: 2, management_eligible: true, environment: 'preview', claims: { expected_version: { value: '1.2.3', source: 'manual_operator' }, deployment_commit_sha: { value: '1'.repeat(40), source: 'manual_operator' }, release_id: { value: 'v1.2.3', source: 'manual_operator' } }, observations: { observed_version: null, provenance: provenanceClaims }, verifier_commit_sha: '2'.repeat(40), workflow_sha: '3'.repeat(40), repository: 'owner/plugin', run_id: '100', run_attempt: '1', run_url: 'https://github.com/owner/plugin/actions/runs/100', config_digest: 'a'.repeat(64), overall: 'fail', code: 'repeated_incident_fingerprint', incident: true, incident_fingerprints: ['b'.repeat(24)], completed_at: '2026-08-26T12:00:00.000Z', ...overrides };
}
const ACTIONS_BOT = Object.freeze({ login: 'github-actions[bot]', type: 'Bot' });
function searchResult(items) { const normalized = items.map((item) => Object.hasOwn(item, 'user') ? item : { ...item, user: ACTIONS_BOT }); return { status: 200, body: { total_count: normalized.length, items: normalized }, headers: {} }; }

test('schema v2 supports direct targets and multiple version sources', () => {
  const config = validated();
  assert.deepEqual(Object.keys(config.environment.targets), ['primary', 'cdn']);
  assert.deepEqual(config.environment.versionSources.map((item) => item.id), ['page', 'cdn']);
});

test('all environments are strict and primary target instances are one-to-one', () => {
  const raw = rawConfig(); raw.environments.other = structuredClone(raw.environments.preview);
  assert.throws(() => validateConfig(raw, 'preview'), /duplicate_target_instance/);
  raw.environments.other.targets.primary.base_url = 'https://other.example.com/base/';
  raw.environments.other.targets.cdn.base_url = 'https://other-cdn.example.com/assets/';
  assert.equal(validateConfig(raw, 'preview').environments.other.targets.primary.origin, 'https://other.example.com:443');
  raw.environments.other.unknown = true;
  assert.throws(() => validateConfig(raw, 'preview'), /unknown_key_[0-9a-f]{8}/);
});

test('environment and every target instance have globally canonical unique identities', () => {
  const secondary = rawConfig(); secondary.environments.other = structuredClone(secondary.environments.preview);
  secondary.environments.other.targets.primary.base_url = 'https://other.example.com/base/';
  assert.throws(() => validateConfig(secondary, 'preview'), /duplicate_target_instance/);

  const names = rawConfig(); names.environments.Preview = structuredClone(names.environments.preview);
  names.environments.Preview.targets.primary.base_url = 'https://other.example.com/base/';
  names.environments.Preview.targets.cdn.base_url = 'https://other-cdn.example.com/assets/';
  assert.throws(() => validateConfig(names, 'preview'), /duplicate_environment_name_case_insensitive/);

  const trailing = rawConfig(); trailing.environments.preview.targets.primary.base_url = 'https://public.example.com./base/';
  trailing.environments.preview.targets.cdn.base_url = 'https://public.example.com/assets/';
  assert.throws(() => validateConfig(trailing, 'preview'), /duplicate_target_origin/);
  assert.equal(__test.canonicalOrigin(new URL('https://EXAMPLE.com./')), 'https://example.com:443');
});

test('IPv4/IPv6 literals, duplicate origins and base-path escapes are rejected', () => {
  for (const base_url of ['https://127.0.0.1/', 'https://[::1]/']) { const raw = rawConfig(); raw.environments.preview.targets.primary.base_url = base_url; assert.throws(() => validateConfig(raw, 'preview'), /ip_literal_forbidden/); }
  const duplicate = rawConfig(); duplicate.environments.preview.targets.cdn.base_url = 'https://public.example.com/assets/'; assert.throws(() => validateConfig(duplicate, 'preview'), /duplicate_target_origin/);
  assert.throws(() => validateConfig(rawConfig({ redirects: [{ from: 'https://public.example.com/base/start', to: 'https://public.example.com/outside' }] }), 'preview'), /outside_target_base_path/);
  const pathGrant = rawConfig(); pathGrant.environments.preview.targets.cdn.base_url = 'https://cdn.example.com/assets'; assert.throws(() => validateConfig(pathGrant, 'preview'), /base_path_must_end_slash/);
});

test('path isolation rejects raw, encoded and repeatedly encoded separator ambiguity', () => {
  const hostile = [
    '/safe\\..\\escape',
    '/safe/%5c..%5c/escape',
    '/safe/%2f..%2fescape',
    '/safe/%252e%252e/escape',
    '/safe/%255c..%255c/escape',
    '/safe/%25252e%25252e/escape',
  ];
  for (const requestPath of hostile) {
    const raw = rawConfig(); raw.environments.preview.checks[0].path = requestPath;
    assert.throws(() => validateConfig(raw, 'preview'), /backslash_forbidden|encoded_separator_forbidden|traversal_forbidden|encoding_depth_exceeded/);
  }
  const valid = rawConfig(); valid.environments.preview.checks[0].path = '/documents/My%20File/';
  assert.equal(validateConfig(valid, 'preview').environment.checks[0].path, '/documents/My%20File/');
});

test('config rejects unsafe capabilities and impossible limits', () => {
  const mutations = [
    (raw) => { raw.environments.preview.checks[0].method = 'POST'; },
    (raw) => { raw.environments.preview.checks[0].expected_status = 302; },
    (raw) => { raw.environments.preview.checks[0].regex = '.*'; },
    (raw) => { raw.environments.preview.targets.cdn.basic_auth = true; },
    (raw) => { raw.limits.max_attempts = 1; },
    (raw) => { raw.limits.max_requests_per_attempt = 2; raw.limits.max_total_requests = 6; },
    (raw) => { raw.provenance.release_asset_name = '../bad-{version}.zip'; },
  ];
  for (const mutate of mutations) { const raw = rawConfig(); mutate(raw); assert.throws(() => validateConfig(raw, 'preview')); }
});

test('configuration rejects empty required evidence and required unobservable checks fail closed', async () => {
  const empty = rawConfig();
  for (const check of empty.environments.preview.checks) check.required = false;
  for (const source of empty.environments.preview.version_sources) source.required = false;
  assert.throws(() => validateConfig(empty, 'preview'), /required_evidence_missing/);

  const config = validated({ checks: [{ id: 'private-only', target: 'primary', type: 'page', path: '/', method: 'GET', expected_status: 200, required: true, fatal_signatures: false, required_text: [], forbidden_text: [], observable: false }], versionSources: [] });
  await withEnv(baseEnv, async () => {
    const report = await verify(config, { now: () => Date.parse('2026-08-26T12:00:00Z'), provenance: provenanceClaims, fs: { mkdirSync() {}, writeFileSync() {}, appendFileSync() {} } });
    assert.equal(report.overall, 'not_observable_read_only');
    assert.equal(report.incident, false);
    assert.equal(report.metadata.observations.observed_version, null);
  });
});

test('unsafe IP ranges are rejected while public unicast is accepted', () => {
  for (const value of ['10.0.0.1', '127.0.0.1', '169.254.169.254', '192.168.1.1', '::1', 'fc00::1', 'fe80::1', '64:ff9b::a00:1', '2001:db8::1', '2002:0a00:1::']) assert.equal(isUnsafeIp(value), true, value);
  assert.equal(isUnsafeIp('8.8.8.8'), false); assert.equal(isUnsafeIp('2606:4700:4700::1111'), false);
});

test('per-check fingerprints distinguish checks and survive companion faults', () => {
  const first = __test.failureResult('check-a', true, { code: 'same', status: 'fail', incidentEligible: true });
  const second = __test.failureResult('check-b', true, { code: 'same', status: 'fail', incidentEligible: true });
  assert.notEqual(first.fingerprint, second.fingerprint);
  const decision = decideAttempts([{ status: 'fail', incidentFingerprints: [first.fingerprint, '1'.repeat(24)] }, { status: 'fail', incidentFingerprints: [first.fingerprint, '2'.repeat(24)] }], LIMITS);
  assert.deepEqual(decision.incidentFingerprints, [first.fingerprint]);
});

test('attempt table and result priority are exact', () => {
  const pass = { status: 'pass', incidentFingerprints: [] }; const fail = { status: 'fail', incidentFingerprints: ['a'.repeat(24)] }; const blocked = { status: 'blocked', incidentFingerprints: ['b'.repeat(24)] };
  assert.equal(decideAttempts([pass, pass], LIMITS).overall, 'pass'); assert.equal(decideAttempts([fail, pass, pass], LIMITS).overall, 'pass');
  assert.equal(decideAttempts([pass, fail, pass], LIMITS).code, 'stability_requirement_not_met'); assert.equal(decideAttempts([blocked, blocked], LIMITS).overall, 'blocked');
  assert.equal(decideAttempts([{ status: 'not_observable_read_only', incidentFingerprints: [] }], LIMITS).overall, 'not_observable_read_only');
  const classified = __test.classifyAttempt([{ id: 'x', required: true, status: 'not_observable_read_only', code: 'x', incident_eligible: false }, { id: 'y', required: true, status: 'blocked', code: 'y', fingerprint: '1'.repeat(24), incident_eligible: true }, { id: 'z', required: true, status: 'fail', code: 'z', fingerprint: '2'.repeat(24), incident_eligible: true }], []);
  assert.equal(classified.status, 'fail');
});

test('redirects are declared, base-confined, counted and strip cross-target auth', async () => {
  const config = validated({ redirects: [{ from: 'https://public.example.com/base/start', to: 'https://cdn.example.com/assets/final' }] });
  const calls = [];
  const context = { targets: config.environment.targets, allowedRedirects: config.environment.allowedRedirects, auth: { username: 'u', password: 'p' }, limits: config.limits, totalRequests: 0, attemptRequests: 0, phaseDeadline: 100000, now: () => 0, dependencies: { setTimeout, clearTimeout }, resolvePublic: async () => [{ address: '93.184.216.34', family: 4 }], requestOnce: async (url, method, auth) => { calls.push({ url: url.href, method, auth }); return calls.length === 1 ? response(302, '', { location: 'https://cdn.example.com/assets/final', 'set-cookie': 'ignored=x' }) : response(200, 'ok'); } };
  assert.equal((await __test.boundedRequest(new URL('https://public.example.com/base/start'), 'GET', context)).statusCode, 200);
  assert.deepEqual(calls.map((item) => item.auth), [context.auth, null]); assert.equal(context.totalRequests, 2);
  await assert.rejects(__test.boundedRequest(new URL('https://public.example.com/outside'), 'GET', { ...context, attemptRequests: 0 }), /outside_target_base_path/);
});

test('bounded requests fall back to global timers when dependencies omit timer functions', async () => {
  const config = validated();
  const calls = [];
  const context = { targets: config.environment.targets, allowedRedirects: new Map(), auth: null, limits: config.limits, totalRequests: 0, attemptRequests: 0, phaseDeadline: 100000, now: () => 0, dependencies: {}, resolvePublic: async () => { calls.push('resolvePublic'); return [{ address: '93.184.216.34', family: 4 }]; }, requestOnce: async () => { calls.push('requestOnce'); return response(200, 'Ready'); } };
  const result = await __test.boundedRequest(new URL('https://public.example.com/base/'), 'GET', context);
  assert.equal(result.statusCode, 200);
  assert.deepEqual(calls, ['resolvePublic', 'requestOnce']);
});

test('redirect and request budgets reject undeclared, downgrade and exhausted paths', async () => {
  const config = validated();
  const base = { targets: config.environment.targets, allowedRedirects: new Map(), auth: null, limits: config.limits, totalRequests: 0, attemptRequests: 0, phaseDeadline: 100000, now: () => 0, dependencies: { setTimeout, clearTimeout }, resolvePublic: async () => [{ address: '93.184.216.34', family: 4 }], requestOnce: async () => response(302, '', { location: 'https://cdn.example.com/assets/x' }) };
  await assert.rejects(__test.boundedRequest(new URL('https://public.example.com/base/start'), 'GET', base), /undeclared_transition/);
  const downgrade = { ...base, totalRequests: 0, attemptRequests: 0, allowedRedirects: new Map([['https://public.example.com:443/base/start', 'http://public.example.com:80/base/x']]), requestOnce: async () => response(302, '', { location: 'http://public.example.com/base/x' }) };
  await assert.rejects(__test.boundedRequest(new URL('https://public.example.com/base/start'), 'GET', downgrade), /https_downgrade/);
  await assert.rejects(__test.boundedRequest(new URL('https://public.example.com/base/start'), 'HEAD', { ...base, attemptRequests: 10, requestOnce: async () => assert.fail() }), /request_limit/);
  await assert.rejects(__test.boundedRequest(new URL('https://public.example.com/base/start'), 'HEAD', { ...base, phaseDeadline: 30000, requestOnce: async () => assert.fail() }), /phase_deadline/);
});

test('headers, decompression and teardown paths are bounded', async () => {
  assert.throws(() => __test.inspectHeaders(Array.from({ length: 130 }, (_, index) => index % 2 ? 'v' : 'x'), LIMITS), /too_many_headers/);
  const compressed = new PassThrough(); compressed.headers = { 'content-encoding': 'gzip' }; const promise = __test.readDecodedBody(compressed, 'GET', { ...LIMITS, max_body_bytes: 100 }); compressed.end(gzipSync(Buffer.alloc(1000, 65))); await assert.rejects(promise, /decoded_body_too_large/);
  const unsupported = new PassThrough(); unsupported.headers = { 'content-encoding': 'compress' }; await assert.rejects(__test.readDecodedBody(unsupported, 'GET', LIMITS), /unsupported_content_encoding/);
  const head = new PassThrough(); head.headers = {}; assert.equal(await __test.readDecodedBody(head, 'HEAD', LIMITS), '');
});

function fakeHttpsResponse({ status = 200, body = '{}', headers = {}, rawHeaders = [] } = {}) {
  return () => { const request = new EventEmitter(); request.write = () => {}; request.destroy = (error) => { if (error) queueMicrotask(() => request.emit('error', error)); }; request.end = () => queueMicrotask(() => { const socket = new EventEmitter(); socket.remoteAddress = '93.184.216.34'; socket.destroy = (error) => request.emit('error', error); request.emit('socket', socket); socket.emit('secureConnect'); const stream = new PassThrough(); stream.statusCode = status; stream.headers = headers; stream.rawHeaders = rawHeaders; request.emit('response', stream); stream.end(body); }); return request; };
}

test('requestOnce executes fixed lookup and destroys malformed responses', async () => {
  const result = await __test.requestOnce(new URL('https://public.example.com/base/'), 'GET', null, LIMITS, [{ address: '93.184.216.34', family: 4 }], { httpsRequest: fakeHttpsResponse({ status: 200, body: 'Ready', headers: { 'content-encoding': 'identity' }, rawHeaders: ['content-type', 'text/plain'] }), setTimeout, clearTimeout });
  assert.equal(result.body, 'Ready');
  await assert.rejects(__test.requestOnce(new URL('https://public.example.com/base/'), 'GET', null, LIMITS, [{ address: '93.184.216.34', family: 4 }], { httpsRequest: fakeHttpsResponse({ rawHeaders: Array.from({ length: 130 }, (_, index) => index % 2 ? 'v' : 'x') }), setTimeout, clearTimeout }), /too_many_headers/);
});

test('requestOnce fixed lookup honors Node all-address callback mode', async () => {
  let request;
  let options;
  const pending = __test.requestOnce(new URL('https://public.example.com/base/'), 'GET', null, LIMITS, [{ address: '93.184.216.34', family: 4 }], {
    httpsRequest: (_url, requestOptions) => {
      options = requestOptions;
      request = new EventEmitter();
      request.destroy = () => {};
      request.end = () => {};
      return request;
    },
    setTimeout,
    clearTimeout,
  });

  let result;
  options.lookup('public.example.com', { all: true }, (error, addresses) => { result = { error, addresses }; });
  request.emit('error', Object.assign(new Error('closed'), { code: 'ECONNRESET' }));
  await assert.rejects(pending, /target_network:econnreset/);

  assert.equal(result.error, null);
  assert.deepEqual(result.addresses, [{ address: '93.184.216.34', family: 4 }]);
  assert.equal(__test.cleanError(Object.assign(new Error('invalid'), { code: 'ERR_INVALID_IP_ADDRESS' })).code, 'internal:lookup_contract_invalid');
});

test('DNS resolver blocks empty, private and network errors', async () => {
  await assert.rejects(__test.resolvePublic('x', { dnsLookup: async () => [] }), /dns_empty/);
  await assert.rejects(__test.resolvePublic('x', { dnsLookup: async () => [{ address: '10.0.0.1', family: 4 }] }), /dns_non_public/);
  await assert.rejects(__test.resolvePublic('x', { dnsLookup: async () => { throw Object.assign(new Error(), { code: 'ENOTFOUND' }); } }), /enotfound/);
  assert.equal((await __test.resolvePublic('x', { dnsLookup: async () => [{ address: '8.8.8.8', family: 4 }] }))[0].address, '8.8.8.8');
});

test('verification passes only after two consecutive complete observations', async () => {
  await withEnv(baseEnv, async () => {
    const reports = [];
    const report = await verify(validated(), {
      now: (() => { let value = Date.parse('2026-08-26T12:00:00Z'); return () => value += 10; })(),
      performRequest: async (url, method, context) => {
        context.totalRequests += 1;
        context.attemptRequests += 1;
        return successTarget(url, method);
      },
      sleep: async () => {},
      provenance: provenanceClaims,
      fs: { mkdirSync() {}, writeFileSync() {}, appendFileSync() {} },
    });
    reports.push(report);
    assert.equal(report.overall, 'pass');
    assert.equal(report.attempts.length, 2);
    assert.equal(report.budgets.requests_used, 6);
    assert.equal(report.metadata.observations.observed_version, '1.2.3');
    assert.equal(report.metadata.verifier_commit_sha, baseEnv.VERIFIER_COMMIT_SHA);
    assert.equal(report.metadata.claims.deployment_commit_sha.value, baseEnv.DEPLOYMENT_COMMIT_SHA);
  });
});

const watcherEnv = Object.freeze({ ...baseEnv, CLAIM_SOURCE: 'stable_release_watcher', INVOCATION_KIND: 'call', DEPLOYMENT_CONCLUSION: 'success', EXPECTED_VERSION: 'v1.2.3', RELEASE_ID: 'v1.2.3', GITHUB_EVENT_NAME: 'workflow_call', WATCH_FOR_VERSION: 'true' });

function watcherTargetFails(url, method, context) {
  context.totalRequests += 1;
  context.attemptRequests += 1;
  return url.hostname === 'public.example.com' ? response(500, 'Fatal error: broken') : successTarget(url, method);
}

test('stable_release_watcher claims round-trip through management payload and issue creation (regression)', async () => {
  await withEnv(watcherEnv, async () => {
    const report = await verify(validated({ versionSources: [{ id: 'release-tag', target: 'cdn', type: 'response_header', path: '/plugin.css', required: true, header_name: 'x-release-version' }] }), {
      now: () => Date.parse('2026-08-26T12:00:00Z'), sleep: async () => {}, provenance: provenanceClaims,
      performRequest: watcherTargetFails,
      fs: { mkdirSync() {}, writeFileSync() {}, appendFileSync() {} },
    });
    assert.equal(report.overall, 'blocked');
    assert.equal(report.code, 'release_not_observed_within_bound');
    assert.equal(report.metadata.claims.expected_version.value, '1.2.3');
    assert.equal(report.metadata.claims.expected_version.source, 'stable_release_watcher');

    const payload = parseManagementPayload(encode(__test.managementPayload(report)));
    assert.equal(payload.claims.deployment_commit_sha.source, 'stable_release_watcher');
    assert.equal(payload.incident, true);
    assert.ok(payload.incident_fingerprints.length >= 1);
    assert.equal(payload.observations.observed_version, null);
    const writes = [];
    let page = 0;
    assert.equal(await manageIssue(payload, async (method, endpoint, body) => {
      if (method === 'GET') return ++page === 1 ? searchResult([]) : searchResult([]);
      writes.push({ method, body });
      return { status: 201, body: {}, headers: {} };
    }), 'changed');
    assert.deepEqual(writes.map((item) => item.method), ['POST']);
  });
});

test('a failing required homepage can never produce a pass even with the exact Stable tag (regression)', async () => {
  await withEnv({ ...watcherEnv, WATCH_FOR_VERSION: 'false' }, async () => {
    const report = await verify(validated(), {
      now: () => Date.parse('2026-08-26T12:00:00Z'), sleep: async () => {}, provenance: provenanceClaims,
      performRequest: watcherTargetFails,
      fs: { mkdirSync() {}, writeFileSync() {}, appendFileSync() {} },
    });
    assert.equal(report.overall, 'fail');
    assert.ok(report.attempts.every((attempt) => attempt.checks.some((item) => item.id === 'home' && item.status === 'fail')));
    assert.doesNotMatch(JSON.stringify(report), /all_required_checks_passed/u);
  });
});

test('mixed required version sources fail and never publish an observed stable version', async () => {
  await withEnv(baseEnv, async () => {
    const report = await verify(validated(), {
      now: () => Date.parse('2026-08-26T12:00:00Z'),
      performRequest: async (url, method) => url.hostname === 'cdn.example.com' ? response(200, '', { 'x-release-version': '9.9.9' }) : successTarget(url, method),
      sleep: async () => {}, provenance: provenanceClaims,
      fs: { mkdirSync() {}, writeFileSync() {}, appendFileSync() {} },
    });
    assert.equal(report.overall, 'fail');
    assert.equal(report.incident, true);
    assert.equal(report.metadata.observations.observed_version, null);
    assert.match(JSON.stringify(report), /target_version:mismatch/);
  });
});

test('pass-fail-pass is a nonincident stability failure', async () => {
  await withEnv(baseEnv, async () => {
    let primaryCalls = 0;
    const report = await verify(validated({ versionSources: [{ id: 'page', target: 'primary', type: 'text_marker', path: '/', required: true, prefix: 'Version: ', suffix: '\n' }] }), {
      now: () => Date.parse('2026-08-26T12:00:00Z'), sleep: async () => {}, provenance: provenanceClaims,
      performRequest: async (url) => {
        primaryCalls += 1;
        const attempt = Math.ceil(primaryCalls / 2);
        return response(200, `${attempt === 2 ? 'Broken' : 'Ready'}\nVersion: 1.2.3\n`);
      },
      fs: { mkdirSync() {}, writeFileSync() {}, appendFileSync() {} },
    });
    assert.equal(report.overall, 'fail');
    assert.equal(report.code, 'stability_requirement_not_met');
    assert.equal(report.incident, false);
  });
});

test('missing configured Basic Auth still emits a bounded blocked report and evidence', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'release-verification-'));
  try {
    await withEnv({ ...baseEnv, OUTPUT_DIR: directory, TARGET_BASIC_AUTH_USERNAME: undefined, TARGET_BASIC_AUTH_PASSWORD: undefined }, async () => {
      const report = await runVerifyMode({ loadConfig: () => validated({ basicAuth: true }), now: () => Date.parse('2026-08-26T12:00:00Z') });
      assert.equal(report.overall, 'blocked');
      assert.equal(report.code, 'config:target_basic_auth_missing_or_invalid');
      assert.equal(report.management_eligible, false);
      assert.equal(report.metadata.observations.provenance, null);
      const payload = parseManagementPayload(encode(__test.managementPayload(report)));
      assert.equal(payload.management_eligible, false);
      assert.equal(await manageIssue(payload, async () => assert.fail('ineligible setup must not call GitHub')), 'no_issue');
      assert.equal(fs.existsSync(path.join(directory, 'report.json')), true);
      assert.equal(fs.existsSync(path.join(directory, 'junit.xml')), true);
    });
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});

test('invalid setup is blocked and explicitly ineligible for incident management', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'release-verification-'));
  try {
    await withEnv({ OUTPUT_DIR: directory, TARGET_ENVIRONMENT: 'bad environment' }, async () => {
      const report = await runVerifyMode({ loadConfig: () => { throw new Error('secret\n::warning::never'); }, now: () => Date.parse('2026-08-26T12:00:00Z') });
      assert.equal(report.overall, 'blocked');
      assert.equal(report.management_eligible, false);
      assert.doesNotMatch(JSON.stringify(report), /secret|warning|never/iu);
    });
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});

test('JUnit reports real counts and all emitted evidence remains redacted', async () => {
  const report = await withEnv(baseEnv, async () => __test.blockedReport(Object.assign(new Error('password=hunter2\n::error::x'), { code: 'BAD\n::warning::x' }), validated(), { now: () => Date.parse('2026-08-26T12:00:00Z') }));
  const xml = __test.junitXml(report);
  assert.match(xml, /tests="1" failures="0" errors="1"/);
  assert.doesNotMatch(`${JSON.stringify(report)}${xml}`, /hunter2|password=|::error::/iu);
  assert.match(report.code, /^[a-z0-9_:.-]+$/u);
});

test('preflight accepts dispatch and successful call and rejects PR events', async () => {
  const request = async (method, endpoint) => {
    assert.equal(method, 'GET');
    if (endpoint === '/repos/owner/plugin') return { status: 200, body: { visibility: 'public', private: false }, headers: {} };
    if (endpoint.includes('/environments/preview')) return { status: 200, body: {}, headers: {} };
    assert.fail(endpoint);
  };
  await withEnv(baseEnv, async () => assert.equal((await preflight(validated(), request)).environmentDigest.length, 24));
  await withEnv({ ...baseEnv, GITHUB_EVENT_NAME: 'workflow_call', INVOCATION_KIND: 'call', MANUAL_INSTALL_CONFIRMED: 'false', DEPLOYMENT_CONCLUSION: 'success', CLAIM_SOURCE: 'deployment_caller' }, async () => assert.equal((await preflight(validated(), request)).provenance.status, 'owner_assertion'));
  await withEnv({ ...baseEnv, GITHUB_EVENT_NAME: 'pull_request' }, async () => assert.rejects(preflight(validated(), request), /untrusted_pr_event_forbidden/));
  await withEnv({ ...baseEnv, MANUAL_INSTALL_CONFIRMED: 'false' }, async () => assert.rejects(preflight(validated(), request), /manual_install_not_confirmed/));
});

test('preflight does not depend on GitHub Environments and rejects non-public repositories', async () => {
  await withEnv(baseEnv, async () => {
    await assert.rejects(preflight(validated(), async () => ({ status: 200, body: { visibility: 'private', private: true }, headers: {} })), /repository_must_be_public/);
    let sawEnvironmentEndpoint = false;
    const request = async (method, endpoint) => {
      if (endpoint.includes('/environments/')) { sawEnvironmentEndpoint = true; return { status: 404, body: {}, headers: {} }; }
      return { status: 200, body: { visibility: 'public', private: false }, headers: {} };
    };
    await assert.doesNotReject(preflight(validated(), request));
    assert.equal(sawEnvironmentEndpoint, false);
  });
});

test('required GitHub release provenance binds release, asset, tag and deployment commit', async () => {
  await withEnv(baseEnv, async () => {
    const config = validated({ provenanceMode: 'github_release_required' });
    const calls = [];
    const request = async (method, endpoint) => {
      calls.push(endpoint);
      if (endpoint === '/repos/owner/plugin') return { status: 200, body: { visibility: 'public', private: false }, headers: {} };
      if (endpoint.includes('/environments/preview')) return { status: 200, body: {}, headers: {} };
      if (endpoint.includes('/releases/tags/')) return { status: 200, body: { id: 77, tag_name: 'v1.2.3', draft: false, prerelease: false, assets: [{ id: 88, name: 'plugin-1.2.3.zip', size: 1234, digest: `sha256:${'a'.repeat(64)}` }] }, headers: {} };
      if (endpoint.includes('/git/ref/tags/')) return { status: 200, body: { object: { type: 'tag', sha: '4'.repeat(40) } }, headers: {} };
      if (endpoint.includes('/git/tags/')) return { status: 200, body: { object: { type: 'commit', sha: '1'.repeat(40) } }, headers: {} };
      assert.fail(endpoint);
    };
    const result = await preflight(config, request);
    assert.equal(result.provenance.status, 'verified');
    assert.equal(result.provenance.release_tag_commit_sha, baseEnv.DEPLOYMENT_COMMIT_SHA);
    assert.equal(calls.length, 4);
    const mismatchRequest = async (method, endpoint) => endpoint.includes('/git/tags/') ? { status: 200, body: { object: { type: 'commit', sha: '9'.repeat(40) } }, headers: {} } : request(method, endpoint);
    await assert.rejects(preflight(config, mismatchRequest), /provenance_deployment_commit_mismatch/);
  });
});

test('GitHub control requests retain only safe rate-limit headers', async () => {
  await withEnv({ GITHUB_TOKEN: 'not-recorded' }, async () => {
    const result = await githubRequest('GET', '/repos/owner/plugin', undefined, { httpsRequest: fakeHttpsResponse({ body: '{}', headers: { 'x-ratelimit-remaining': '0', 'x-ratelimit-reset': '123', 'set-cookie': 'secret=x', 'x-private': 'no' } }) });
    assert.deepEqual(result.headers, { 'x-ratelimit-remaining': '0', 'x-ratelimit-reset': '123' });
    assert.equal(__test.githubFailure(result, 'repository').code, 'github_control:repository_rate_limit');
  });
});

test('management payload schema and cross-field invariants are strict', () => {
  assert.deepEqual(parseManagementPayload(encode(management())), management());
  assert.throws(() => parseManagementPayload(encode(management({ run_url: 'https://evil.example/' }))), /run_url_mismatch/);
  assert.throws(() => parseManagementPayload(encode(management({ overall: 'pass', code: 'stable_pass', incident: true }))), /management_pass_inconsistent|management_incident/);
  assert.throws(() => parseManagementPayload(encode({ ...management(), extra: true })), /unknown_key/);
});

test('nonincident results never search or write Issues', async () => {
  const result = await manageIssue(management({ incident: false, incident_fingerprints: [], code: 'stability_requirement_not_met' }), async () => assert.fail('GitHub must not be called'));
  assert.equal(result, 'no_issue');
});

test('forged public Issues cannot poison, update, or close the automation lifecycle', async () => {
  const future = management({ run_id: '999', run_url: 'https://github.com/owner/plugin/actions/runs/999', completed_at: '2026-08-26T23:00:00.000Z' });
  const forged = { number: 66, state: 'open', user: { login: 'public-user', type: 'User' }, body: __test.issueBody(future, 'b'.repeat(24)) };
  const wrongType = { number: 67, state: 'open', user: { login: 'github-actions[bot]', type: 'User' }, body: __test.issueBody(future, 'b'.repeat(24)) };
  const writes = [];
  assert.equal(await manageIssue(management(), async (method, endpoint, body) => { if (method === 'GET') return searchResult([forged, wrongType]); writes.push({ method, endpoint, body }); return { status: 201, body: {}, headers: {} }; }), 'changed');
  assert.deepEqual(writes.map((item) => item.method), ['POST']);

  const recovery = management({ run_id: '101', run_url: 'https://github.com/owner/plugin/actions/runs/101', overall: 'pass', code: 'stable_pass', incident: false, incident_fingerprints: [], observations: { observed_version: '1.2.3', provenance: provenanceClaims }, completed_at: '2026-08-26T12:01:00.000Z' });
  let recoveryWrites = 0;
  assert.equal(await manageIssue(recovery, async (method) => method === 'GET' ? searchResult([forged, wrongType]) : (recoveryWrites += 1, { status: 200, body: {}, headers: {} })), 'recovered');
  assert.equal(recoveryWrites, 0);
});

test('121 forged matching Issues cannot flood out a real incident creation', async () => {
  const future = management({ run_id: '999', run_url: 'https://github.com/owner/plugin/actions/runs/999', completed_at: '2026-08-26T23:00:00.000Z' });
  const forged = Array.from({ length: 121 }, (_, index) => ({ number: 1000 + index, state: 'open', user: { login: `public-user-${index}`, type: 'User' }, body: __test.issueBody(future, 'b'.repeat(24)) }));
  const calls = [];
  let searchPage = 0;
  const result = await manageIssue(management(), async (method, endpoint, body) => {
    calls.push({ method, endpoint, body });
    if (method === 'GET') return ++searchPage === 1 ? searchResult(forged) : searchResult([]);
    return { status: 201, body: {}, headers: {} };
  });
  assert.equal(result, 'changed');
  assert.deepEqual(calls.filter((call) => call.method !== 'GET').map((call) => call.method), ['POST']);
  assert.ok(calls.filter((call) => call.method === 'GET').every((call) => decodeURIComponent(call.endpoint).includes('author:app/github-actions')));
});

test('mixed forged and trusted results derive lifecycle decisions only from the bot record', async () => {
  const forgedFuture = management({ run_id: '999', run_url: 'https://github.com/owner/plugin/actions/runs/999', completed_at: '2026-08-26T23:00:00.000Z' });
  const trustedOlder = management({ run_id: '99', run_url: 'https://github.com/owner/plugin/actions/runs/99', completed_at: '2026-08-26T11:59:00.000Z' });
  const forged = { number: 70, state: 'open', user: { login: 'public-user', type: 'User' }, body: __test.issueBody(forgedFuture, 'b'.repeat(24)) };
  const trusted = { number: 71, state: 'open', user: ACTIONS_BOT, body: __test.issueBody(trustedOlder, 'b'.repeat(24)) };
  const writes = [];
  assert.equal(await manageIssue(management(), async (method, endpoint, body) => { if (method === 'GET') return searchResult([forged, trusted]); writes.push({ method, endpoint, body }); return { status: 200, body: {}, headers: {} }; }), 'changed');
  assert.deepEqual(writes.map((item) => [item.method, item.endpoint]), [['PATCH', '/repos/owner/plugin/issues/71']]);
});

test('forged flooding cannot block trusted recovery or supersession', async () => {
  const forgedPayload = management({ run_id: '999', run_url: 'https://github.com/owner/plugin/actions/runs/999', completed_at: '2026-08-26T23:00:00.000Z' });
  const forged = Array.from({ length: 121 }, (_, index) => ({ number: 2000 + index, state: 'open', user: { login: `public-${index}`, type: 'User' }, body: __test.issueBody(forgedPayload, 'b'.repeat(24)) }));
  const trustedIncident = { number: 80, state: 'open', user: ACTIONS_BOT, body: __test.issueBody(management(), 'b'.repeat(24)) };
  const recovery = management({ run_id: '102', run_url: 'https://github.com/owner/plugin/actions/runs/102', overall: 'pass', code: 'stable_pass', incident: false, incident_fingerprints: [], observations: { observed_version: '1.2.3', provenance: provenanceClaims }, completed_at: '2026-08-26T12:02:00.000Z' });
  const recoveryWrites = [];
  let recoveryPage = 0;
  await manageIssue(recovery, async (method, endpoint, body) => { if (method === 'GET') return ++recoveryPage === 1 ? searchResult([...forged, trustedIncident]) : searchResult([]); recoveryWrites.push({ endpoint, body }); return { status: 200, body: {}, headers: {} }; });
  assert.deepEqual(recoveryWrites.map((item) => item.endpoint), ['/repos/owner/plugin/issues/80']);

  const verified = { mode: 'github_release_required', status: 'verified', github_release_id: '77', release_tag_commit_sha: '1'.repeat(40), asset: { id: '88', name: 'plugin-1.2.4.zip', size: 1234, digest: null } };
  const newer = management({ run_id: '200', run_url: 'https://github.com/owner/plugin/actions/runs/200', completed_at: '2026-08-26T13:00:00.000Z', overall: 'pass', code: 'stable_pass', incident: false, incident_fingerprints: [], claims: { ...management().claims, expected_version: { value: '1.2.4', source: 'manual_operator' }, release_id: { value: 'v1.2.4', source: 'manual_operator' } }, observations: { observed_version: '1.2.4', provenance: verified } });
  const oldTrusted = { number: 81, state: 'open', user: ACTIONS_BOT, body: __test.issueBody(management(), 'b'.repeat(24)) };
  const supersedeWrites = [];
  let search = 0;
  await manageIssue(newer, async (method, endpoint, body) => {
    if (method === 'GET') {
      search += 1;
      if (search === 1 || search === 2) return searchResult(search === 1 ? forged : []);
      return searchResult(search === 3 ? [...forged, oldTrusted] : []);
    }
    supersedeWrites.push({ endpoint, body }); return { status: 200, body: {}, headers: {} };
  });
  assert.deepEqual(supersedeWrites.map((item) => item.endpoint), ['/repos/owner/plugin/issues/81']);
});

test('trusted automation search is bounded to two pages and 120 records', async () => {
  const payload = management();
  const botIssues = (start, count) => Array.from({ length: count }, (_, index) => ({ number: start + index, state: 'open', user: ACTIONS_BOT, body: __test.issueBody(payload, 'b'.repeat(24)) }));
  let calls = 0;
  await assert.rejects(__test.searchIssues(payload, 'version', async (method, endpoint) => {
    calls += 1;
    assert.equal(method, 'GET');
    assert.match(decodeURIComponent(endpoint), /author:app\/github-actions/u);
    return calls === 1 ? searchResult(botIssues(1, 100)) : searchResult(botIssues(101, 21));
  }), /trusted_issue_search_limit/);
  assert.equal(calls, 2);

  calls = 0;
  const bounded = await __test.searchIssues(payload, 'version', async () => ++calls === 1 ? searchResult(botIssues(1, 100)) : searchResult(botIssues(101, 20)));
  assert.equal(bounded.length, 120);
  assert.equal(calls, 2);
});

test('repeated fingerprints create exact marked issues and deduplicate stale runs', async () => {
  const payload = management({ incident_fingerprints: ['b'.repeat(24), 'c'.repeat(24)] });
  const calls = [];
  const request = async (method, endpoint, body) => { calls.push({ method, endpoint, body }); return method === 'GET' ? searchResult([]) : { status: 201, body: {}, headers: {} }; };
  assert.equal(await manageIssue(payload, request), 'changed');
  assert.equal(calls.filter((call) => call.method === 'POST').length, 2);
  assert.match(calls.find((call) => call.method === 'POST').body.body, /release-verification-record:/);

  const stalePayload = management({ incident_fingerprints: ['b'.repeat(24)] });
  const newer = management({ run_id: '101', run_url: 'https://github.com/owner/plugin/actions/runs/101', completed_at: '2026-08-26T12:01:00.000Z' });
  const issue = { number: 7, state: 'open', body: __test.issueBody(newer, 'b'.repeat(24)) };
  assert.ok(__test.parseIssueRecord(issue.body));
  let writes = 0;
  assert.equal(await manageIssue(stalePayload, async (method) => method === 'GET' ? searchResult([issue]) : (writes += 1, { status: 201, body: {}, headers: {} })), 'stale_noop');
  assert.equal(writes, 0);
});

test('recovery closes only newer matching incidents and preserves original evidence', async () => {
  const incident = management();
  const issue = { number: 9, state: 'open', body: __test.issueBody(incident, 'b'.repeat(24)) };
  const recovery = management({ run_id: '102', run_url: 'https://github.com/owner/plugin/actions/runs/102', run_attempt: '2', completed_at: '2026-08-26T12:03:00.000Z', overall: 'pass', code: 'stable_pass', incident: false, incident_fingerprints: [], observations: { observed_version: '1.2.3', provenance: provenanceClaims } });
  const writes = [];
  assert.equal(await manageIssue(recovery, async (method, endpoint, body) => { if (method === 'GET') return searchResult([issue]); writes.push({ endpoint, body }); return { status: 200, body: {}, headers: {} }; }), 'recovered');
  assert.equal(writes.length, 1);
  assert.match(writes[0].body.body, /repeated incident[\s\S]*### Recovery/u);
  const recoveredRecord = __test.parseIssueRecord(writes[0].body.body);
  assert.equal(recoveredRecord.payload.run_id, '102');
  assert.equal(recoveredRecord.payload.overall, 'pass');
});

test('recovery freshness record prevents an older incident from reopening', async () => {
  const incident100 = management();
  const original = { number: 21, state: 'open', body: __test.issueBody(incident100, 'b'.repeat(24)) };
  const recovery102 = management({ run_id: '102', run_url: 'https://github.com/owner/plugin/actions/runs/102', completed_at: '2026-08-26T12:02:00.000Z', overall: 'pass', code: 'stable_pass', incident: false, incident_fingerprints: [], observations: { observed_version: '1.2.3', provenance: provenanceClaims } });
  let recoveryBody;
  await manageIssue(recovery102, async (method, endpoint, body) => { if (method === 'GET') return searchResult([original]); recoveryBody = body.body; return { status: 200, body: {}, headers: {} }; });
  const recovered = { number: 21, state: 'closed', body: recoveryBody };
  assert.equal(__test.parseIssueRecord(recovered.body).payload.run_id, '102');

  const stale101 = management({ run_id: '101', run_url: 'https://github.com/owner/plugin/actions/runs/101', completed_at: '2026-08-26T12:01:00.000Z' });
  let staleWrites = 0;
  assert.equal(await manageIssue(stale101, async (method) => method === 'GET' ? searchResult([recovered]) : (staleWrites += 1, { status: 200, body: {}, headers: {} })), 'stale_noop');
  assert.equal(staleWrites, 0);
});

test('supersession requires a newer proven and observed stable release', async () => {
  const verified = { mode: 'github_release_required', status: 'verified', github_release_id: '77', release_tag_commit_sha: '1'.repeat(40), asset: { id: '88', name: 'plugin-1.2.4.zip', size: 1234, digest: null } };
  const oldPayload = management();
  const oldIssue = { number: 11, state: 'open', body: __test.issueBody(oldPayload, 'b'.repeat(24)) };
  const current = management({ run_id: '200', run_url: 'https://github.com/owner/plugin/actions/runs/200', completed_at: '2026-08-26T13:00:00.000Z', overall: 'pass', code: 'stable_pass', incident: false, incident_fingerprints: [], claims: { ...oldPayload.claims, expected_version: { value: '1.2.4', source: 'manual_operator' }, release_id: { value: 'v1.2.4', source: 'manual_operator' } }, observations: { observed_version: '1.2.4', provenance: verified } });
  let searches = 0; const writes = [];
  await manageIssue(current, async (method, endpoint, body) => { if (method === 'GET') return ++searches === 1 ? searchResult([]) : searchResult([oldIssue]); writes.push({ endpoint, body }); return { status: 200, body: {}, headers: {} }; });
  assert.equal(writes.length, 1);
  assert.equal(writes[0].body.state_reason, 'not_planned');
  assert.match(writes[0].body.body, /### Superseded/u);
  assert.equal(__test.parseIssueRecord(writes[0].body.body).payload.run_id, '200');
});

test('artifact inventory is exactly two small sanitized evidence files', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'release-verification-'));
  try {
    await withEnv({ ...baseEnv, OUTPUT_DIR: directory }, async () => {
      const report = __test.blockedReport(new Error('opaque setup error'), validated(), { now: () => Date.parse('2026-08-26T12:00:00Z') });
      __test.outputFiles(report, LIMITS);
    });
    assert.deepEqual(fs.readdirSync(directory).sort(), ['junit.xml', 'report.json']);
    const evidence = fs.readdirSync(directory).map((name) => fs.readFileSync(path.join(directory, name), 'utf8')).join('\n');
    assert.ok(Buffer.byteLength(evidence) < LIMITS.max_artifact_bytes);
    assert.doesNotMatch(evidence, /cookie|authorization|password|query string=|stack trace:/iu);
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});

test('workflow has one global versionless concurrency key and exactly three isolated jobs', () => {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
  const workflow = fs.readFileSync(path.join(root, '.github', 'workflows', 'release-verification.yml'), 'utf8');
  assert.match(workflow, /^permissions: \{\}$/mu);
  assert.match(workflow, /^concurrency:\n  group: release-verification-\$\{\{ github\.repository \}\}-\$\{\{ github\.event_name == 'release' && 'production' \|\| inputs\.target_environment \}\}\n  cancel-in-progress: true$/mu);
  assert.equal((workflow.match(/^  (?:preflight|verify-target|manage-issue):$/gmu) ?? []).length, 3);
  assert.equal((workflow.match(/^    concurrency:/gmu) ?? []).length, 0);
  assert.doesNotMatch(workflow.match(/^concurrency:[\s\S]*?^jobs:/mu)?.[0] ?? '', /version/iu);
});

test('workflow pins external actions and isolates target secrets from public and issue steps', () => {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
  const workflow = fs.readFileSync(path.join(root, '.github', 'workflows', 'release-verification.yml'), 'utf8');
  const uses = [...workflow.matchAll(/^\s+uses:\s+([^\s#]+)/gmu)].map((match) => match[1]);
  assert.ok(uses.length >= 4);
  assert.ok(uses.every((value) => /@[0-9a-f]{40}$/u.test(value)));
  assert.ok(uses.filter((value) => value.startsWith('actions/checkout@')).every((value) => value.endsWith('3d3c42e5aac5ba805825da76410c181273ba90b1')));
  assert.ok(uses.some((value) => value === 'actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a'));
  assert.ok(workflow.match(/^  workflow_call:[\s\S]*?^    secrets:\n      PRODUCTION_BASE_URL:\n        description: [^\n]+\n        required: true$/gmu), 'PRODUCTION_BASE_URL must be a required workflow_call secret');
  assert.equal([...workflow.matchAll(/^          PRODUCTION_BASE_URL: \$\{\{ secrets\.PRODUCTION_BASE_URL \}\}$/gmu)].length, 3);
  assert.doesNotMatch(workflow, /secrets:\s*inherit/u);
  const publicStep = workflow.match(/- name: Verify public read-only target[\s\S]*?(?=\n      - name: Verify Basic-Auth)/u)?.[0] ?? '';
  const issueJob = workflow.match(/^  manage-issue:[\s\S]*$/mu)?.[0] ?? '';
  assert.match(publicStep, /PRODUCTION_BASE_URL: \$\{\{ secrets\.PRODUCTION_BASE_URL \}\}/u);
  assert.doesNotMatch(publicStep, /TARGET_BASIC_AUTH/u);
  assert.doesNotMatch(issueJob, /TARGET_BASIC_AUTH|PRODUCTION_BASE_URL|PROVENANCE_PAYLOAD|secrets\.|environment:\n/u);
  assert.match(issueJob, /contents: read[\s\S]*issues: write/u);
});

test('workflow exposes stable release, dispatch and call without any persistent schedule', () => {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
  const workflow = fs.readFileSync(path.join(root, '.github', 'workflows', 'release-verification.yml'), 'utf8');
  assert.match(workflow, /^  workflow_dispatch:/mu);
  assert.match(workflow, /^  workflow_call:/mu);
  assert.match(workflow, /^  release:\n    types: \[published\]$/mu);
  assert.doesNotMatch(workflow, /^  (?:pull_request|pull_request_target|schedule):/mu);
  assert.match(workflow, /github\.event\.release\.draft == false && github\.event\.release\.prerelease == false/u);
  assert.match(workflow, /trigger, never evidence that the release ZIP has reached the production target/u);
  assert.match(workflow, /PRODUCTION_BASE_URL: \$\{\{ secrets\.PRODUCTION_BASE_URL \}\}/u);
  assert.doesNotMatch(workflow, /vars\.PRODUCTION_BASE_URL/u);
  assert.match(workflow, /name: release-verification-\$\{\{ needs\.preflight\.outputs\.environment_digest \}\}-\$\{\{ github\.run_id \}\}-\$\{\{ github\.run_attempt \}\}/u);
  assert.doesNotMatch(workflow, /name: release-verification-\$\{\{ needs\.preflight\.outputs\.environment \}\}/u);
  assert.equal([...workflow.matchAll(/WATCH_FOR_VERSION: \$\{\{ github\.event_name == 'workflow_dispatch' && 'false' \|\| 'true' \}\}/gu)].length, 3);
  assert.match(workflow, /verification must not require a[\s\S]*?manually provisioned GitHub Environment/u);
  const verifyJob = workflow.match(/^  verify-target:[\s\S]*?(?=^  \w)/mu)?.[0] ?? '';
  assert.doesNotMatch(verifyJob, /^    environment:\n/mu);
});

test('release pipeline chains verification directly so GITHUB_TOKEN-published releases still start it', () => {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
  const release = fs.readFileSync(path.join(root, '.github', 'workflows', 'release.yml'), 'utf8');
  assert.match(release, /events triggered by\s*\n  # GITHUB_TOKEN never fire `on: release` workflows/u);
  assert.equal((release.match(/uses: \.\/\.github\/workflows\/release-verification\.yml/gu) ?? []).length, 1);
  const caller = release.match(/^  verify-production:\n[\s\S]*$/mu)?.[0] ?? '';
  assert.match(caller, /needs: build-and-release/u);
  assert.match(caller, /needs\.build-and-release\.result == 'success' && !contains\(github\.ref_name, '-rc'\)/u);
  for (const grant of ['contents: read', 'actions: read', 'issues: write']) assert.ok(caller.includes(grant), grant);
  assert.match(caller, /target_environment: production/u);
  assert.match(caller, /expected_version: \$\{\{ github\.ref_name \}\}/u);
  assert.match(caller, /deployment_commit_sha: \$\{\{ github\.sha \}\}/u);
  assert.match(caller, /release_id: \$\{\{ github\.ref_name \}\}/u);
  assert.match(caller, /deployment_conclusion: \$\{\{ needs\.build-and-release\.result \}\}/u);
  assert.match(caller, /secrets:\n      PRODUCTION_BASE_URL: \$\{\{ secrets\.PRODUCTION_BASE_URL \}\}/u);
  assert.doesNotMatch(release, /secrets:\s*inherit/u);
  assert.doesNotMatch(release, /vars\.PRODUCTION_BASE_URL/u);
});

test('workflow enforces a sixteen-minute active-cycle bound and documents reusable permissions', () => {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
  const workflow = fs.readFileSync(path.join(root, '.github', 'workflows', 'release-verification.yml'), 'utf8');
  const timeouts = [...workflow.matchAll(/^    timeout-minutes: (\d+)$/gmu)].map((match) => Number(match[1]));
  assert.deepEqual(timeouts, [2, 12, 2]);
  assert.equal(timeouts.reduce((sum, value) => sum + value, 0), 16);
  assert.match(workflow, /active-execution hard bound:[\s\S]*2 \+ 12 \+ 2 = 16/u);
  assert.match(workflow, /GitHub queue time before a job starts is external/u);
  assert.match(workflow, /caller MUST explicitly grant contents: read, actions: read, and issues: write/u);
  assert.match(workflow, /reusable workflow can reduce caller permissions but cannot elevate them/u);
  const config = JSON.parse(fs.readFileSync(path.join(root, 'tests', 'external', 'verification-config.json'), 'utf8'));
  assert.equal(config.limits.phase_timeout_ms, 600000);
  assert.equal(config.limits.max_attempts, 12);
  assert.equal(config.limits.max_total_requests, 24);
  assert.deepEqual(Object.keys(config.environments), ['production']);
  assert.equal(config.environments.production.targets.primary.base_url, '${PRODUCTION_BASE_URL}');
  assert.equal(config.environments.production.targets.primary.basic_auth, false);
  assert.deepEqual(config.environments.production.allowed_redirects, []);
  const home = config.environments.production.checks.find((item) => item.id === 'production-home');
  assert.equal(home.required, true);
  assert.equal(home.method, 'GET');
  assert.equal(home.expected_status, 200);
});

test('template inventory is exactly the four contract files and contains no runtime language', () => {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
  const contractFiles = ['.github/workflows/release-verification.yml', 'tests/external/verification-config.json', 'tests/external/verify.mjs', 'tests/external/verify.test.mjs'];
  assert.ok(contractFiles.every((name) => fs.statSync(path.join(root, name)).isFile()));
  const externalFiles = fs.readdirSync(path.join(root, 'tests', 'external'), { withFileTypes: true })
    .filter((entry) => entry.isFile())
    .map((entry) => `tests/external/${entry.name}`)
    .sort();
  assert.deepEqual(externalFiles, contractFiles.filter((name) => name.startsWith('tests/external/')).sort());
  assert.equal(contractFiles.some((name) => /\.(?:php|zip)$/iu.test(name)), false);
});

test('page and asset contracts classify every observable outcome without leaking content', async () => {
  const config = validated();
  const target = config.environment.targets.primary;
  const base = { targets: config.environment.targets, performRequest: async () => response(200, 'Ready') };
  const check = { id: 'x', target: 'primary', type: 'page', path: '/', method: 'GET', expected_status: 200, required: true, fatal_signatures: true, required_text: ['Ready'], forbidden_text: ['Forbidden'], observable: true };
  assert.equal((await __test.runCheck({ ...check, observable: false }, base)).status, 'not_observable_read_only');
  const cases = [
    [response(401), 'target_contract:unexpected_auth_status'],
    [response(500), 'target_contract:http_5xx'],
    [response(404), 'target_contract:unexpected_status'],
    [response(200, 'Fatal error: hidden'), 'target_content:fatal_signature'],
    [response(200, 'Different'), 'target_content:required_marker_missing'],
    [response(200, 'Ready Forbidden'), 'target_content:forbidden_marker_present'],
  ];
  for (const [outcome, code] of cases) {
    const result = await __test.runCheck(check, { ...base, performRequest: async () => outcome });
    assert.equal(result.status, 'fail'); assert.equal(result.code, code);
  }
  const authResult = await __test.runCheck(check, { ...base, targets: { ...config.environment.targets, primary: { ...target, basic_auth: true } }, performRequest: async () => response(403) });
  assert.equal(authResult.status, 'blocked'); assert.equal(authResult.code, 'blocked_target_auth');
});

test('all declarative version source modes and bounded failures are executable', async () => {
  const config = validated();
  const context = { targets: config.environment.targets, performRequest: async () => response(200, '') };
  const textSource = { id: 'text', target: 'primary', type: 'text_marker', path: '/', required: true, prefix: 'v=', suffix: ';' };
  assert.equal((await __test.observeVersion(textSource, '1.2.3', { ...context, performRequest: async () => response(200, 'v=1.2.3;') })).status, 'pass');
  assert.equal((await __test.observeVersion(textSource, '1.2.3', context)).code, 'target_version:prefix_missing');
  assert.equal((await __test.observeVersion(textSource, '1.2.3', { ...context, performRequest: async () => response(200, 'v=1.2.3') })).code, 'target_version:suffix_missing');
  const jsonSource = { id: 'json', target: 'primary', type: 'json_manifest', path: '/manifest.json', required: true, json_pointer: '/release/version' };
  assert.equal((await __test.observeVersion(jsonSource, '1.2.3', { ...context, performRequest: async () => response(200, '{"release":{"version":"1.2.3"}}') })).status, 'pass');
  assert.equal((await __test.observeVersion(jsonSource, '1.2.3', { ...context, performRequest: async () => response(200, '{') })).code, 'target_version:invalid_json_manifest');
  assert.equal((await __test.observeVersion(jsonSource, '1.2.3', { ...context, performRequest: async () => response(200, '{}') })).code, 'target_version:json_pointer_missing');
  const headerSource = { id: 'header', target: 'primary', type: 'response_header', path: '/', required: true, header_name: 'x-version' };
  assert.equal((await __test.observeVersion(headerSource, '1.2.3', { ...context, performRequest: async () => response(200, '', { 'x-version': '1.2.3' }) })).status, 'pass');
  assert.equal((await __test.observeVersion(headerSource, '1.2.3', { ...context, performRequest: async () => ({ ...response(200, '', { 'x-version': '1.2.3' }), headerCounts: new Map([['x-version', 2]]) }) })).code, 'target_version:header_missing_or_repeated');
  assert.equal((await __test.observeVersion(headerSource, '1.2.3', { ...context, performRequest: async () => response(200, '', { 'x-version': 'bad value' }) })).code, 'target_version:observed_value_invalid');
  assert.equal((await __test.observeVersion(headerSource, '1.2.3', { ...context, performRequest: async () => response(204) })).code, 'target_version:unexpected_status');
});

test('config validation covers optional observability, HEAD assets and JSON manifests', () => {
  const raw = rawConfig({
    checks: [{ id: 'asset', target: 'cdn', type: 'asset', path: '/plugin.css', method: 'HEAD', expected_status: 200, required: false, fatal_signatures: false, required_text: [], forbidden_text: [], observable: false }],
    versionSources: [{ id: 'manifest', target: 'primary', type: 'json_manifest', path: '/manifest.json', required: true, json_pointer: '/version' }],
  });
  assert.equal(validateConfig(raw, 'preview').environment.checks[0].observable, false);
  const mutations = [
    (copy) => { copy.environments.preview.checks[0].fatal_signatures = true; },
    (copy) => { copy.environments.preview.checks[0].required_text = ['marker']; },
    (copy) => { copy.environments.preview.version_sources[0].json_pointer = 'version'; },
    (copy) => { copy.environments.preview.version_sources[0].json_pointer = '/bad~0'; },
    (copy) => { copy.environments.preview.version_sources[0] = { id: 'header', target: 'primary', type: 'response_header', path: '/', required: true, header_name: 'set-cookie' }; },
  ];
  for (const mutate of mutations) { const copy = structuredClone(raw); mutate(copy); assert.throws(() => validateConfig(copy, 'preview')); }
});

test('gzip, deflate and Brotli decoding share the post-decompression body bound', async () => {
  for (const [encoding, encoded] of [['gzip', gzipSync('ok')], ['deflate', deflateSync('ok')], ['br', brotliCompressSync('ok')]]) {
    const stream = new PassThrough(); stream.headers = { 'content-encoding': encoding };
    const promise = __test.readDecodedBody(stream, 'GET', LIMITS); stream.end(encoded);
    assert.equal(await promise, 'ok');
  }
});

test('error normalization and GitHub failure classification are finite and sanitized', () => {
  assert.equal(__test.cleanError(Object.assign(new Error('details'), { code: 'ECONNREFUSED' })).code, 'target_network:econnrefused');
  assert.equal(__test.cleanError(Object.assign(new Error('details'), { code: 'HPE_HEADER_OVERFLOW' })).code, 'target_protocol:headers_too_large');
  assert.equal(__test.cleanError(new Error('private details')).code, 'internal:unexpected_error');
  assert.equal(__test.githubFailure({ status: 403, headers: {} }, 'environment').code, 'github_control:environment_permission_denied');
  assert.equal(__test.githubFailure({ status: 502, headers: {} }, 'environment').code, 'github_control:environment_api_error');
  assert.equal(__test.githubFailure({ status: 404, headers: {} }, 'environment').code, 'github_control:environment_unexpected_response');
});

test('unknown machine error codes are reported without leaking error details', () => {
  const secret = 'sensitive-token-and-url';
  const error = Object.assign(new Error(`${secret}: https://example.test/private?token=${secret}`), {
    code: 'ERR_SOCKET_BAD_PORT',
    url: `https://example.test/?token=${secret}`,
    body: secret,
  });
  const clean = __test.cleanError(error);
  assert.equal(clean.code, 'internal:runtime_code:err_socket_bad_port');
  assert.equal(clean.status, 'blocked');
  assert.equal(clean.incidentEligible, false);
  assert.equal(`${clean.code}\n${clean.message}\n${clean.stack}`.includes(secret), false);
  assert.equal(__test.cleanError({ code: `ERR_${secret}?token=${secret}` }).code, 'internal:unexpected_error');
});

test('strict management handles ineligible, not-observable, duplicate and invalid provenance payloads', () => {
  const ineligible = { ...management(), management_eligible: false, overall: 'blocked', code: 'config:setup_blocked', incident: false, incident_fingerprints: [], observations: { observed_version: null, provenance: null } };
  assert.equal(parseManagementPayload(encode(ineligible)).management_eligible, false);
  assert.throws(() => parseManagementPayload(encode({ ...ineligible, incident: true })), /management_ineligible_incident_forbidden/);
  assert.throws(() => parseManagementPayload(encode({ ...ineligible, run_id: 'bad' })), /management_ineligible_run_id_invalid/);
  assert.throws(() => parseManagementPayload(encode({ ...ineligible, run_url: 'https://github.com/owner/plugin/actions/runs/999' })), /management_ineligible_run_url_mismatch/);
  assert.throws(() => parseManagementPayload(encode({ ...ineligible, config_digest: 'bad' })), /management_ineligible_config_digest_invalid/);
  assert.throws(() => parseManagementPayload(encode({ ...ineligible, completed_at: 'tomorrow' })), /management_ineligible_completed_at_invalid/);
  assert.throws(() => parseManagementPayload(encode({ ...ineligible, overall: 'pass' })), /management_ineligible_result_invalid/);
  assert.throws(() => parseManagementPayload(encode({ ...ineligible, observations: { observed_version: null, provenance: provenanceClaims } })), /management_ineligible_observations_invalid/);
  assert.throws(() => parseManagementPayload(encode({ ...ineligible, incident_fingerprints: ['b'.repeat(24)] })), /management_ineligible_incident_forbidden/);
  assert.throws(() => parseManagementPayload(encode(management({ incident_fingerprints: ['b'.repeat(24), 'b'.repeat(24)] }))), /management_incident_fingerprints_invalid/);
  const noObservation = management({ overall: 'not_observable_read_only', code: 'requires_session_or_write', incident: false, incident_fingerprints: [] });
  assert.equal(parseManagementPayload(encode(noObservation)).overall, 'not_observable_read_only');
  assert.throws(() => __test.validateProvenance({ ...provenanceClaims, status: 'verified' }), /provenance_claims_only_invalid/);
  assert.throws(() => __test.parseProvenancePayload('%%%'), /provenance_payload_invalid/);
});

test('issue ordering, update, reopen, duplicate detection and version ordering are deterministic', async () => {
  const older = management();
  const current = management({ run_id: '101', run_url: 'https://github.com/owner/plugin/actions/runs/101', completed_at: '2026-08-26T12:05:00.000Z' });
  assert.equal(__test.compareOrder(current, older), 1);
  assert.equal(__test.compareOrder(older, current), -1);
  assert.equal(__test.newerStableVersion('2.0.0', '1.9.9'), true);
  assert.equal(__test.newerStableVersion('1.0.0-beta', '1.0.0'), false);
  const marked = { number: 12, state: 'closed', body: __test.issueBody(older, 'b'.repeat(24)) };
  const writes = [];
  assert.equal(await manageIssue(current, async (method, endpoint, body) => { if (method === 'GET') return searchResult([marked]); writes.push({ method, endpoint, body }); return { status: 200, body: {}, headers: {} }; }), 'changed');
  assert.equal(writes[0].method, 'PATCH'); assert.equal(writes[0].body.state, 'open');
  await assert.rejects(manageIssue(current, async (method) => method === 'GET' ? searchResult([marked, { ...marked, number: 13 }]) : { status: 200, body: {}, headers: {} }), /duplicate_marked_issues/);
});

test('verified provenance validation enforces every asset identity and digest field', () => {
  const value = { mode: 'github_release_required', status: 'verified', github_release_id: '77', release_tag_commit_sha: '1'.repeat(40), asset: { id: '88', name: 'plugin-1.2.3.zip', size: 1234, digest: `sha256:${'a'.repeat(64)}` } };
  assert.deepEqual(__test.validateProvenance(structuredClone(value)), value);
  const mutations = [
    (copy) => { copy.github_release_id = 'bad'; },
    (copy) => { copy.release_tag_commit_sha = 'bad'; },
    (copy) => { copy.asset.id = 'bad'; },
    (copy) => { copy.asset.name = '../bad.zip'; },
    (copy) => { copy.asset.size = -1; },
    (copy) => { copy.asset.digest = 'sha256:bad'; },
    (copy) => { copy.asset.extra = true; },
  ];
  for (const mutate of mutations) { const copy = structuredClone(value); mutate(copy); assert.throws(() => __test.validateProvenance(copy)); }
});

test('config loader bounds bytes, rejects malformed JSON and binds its digest', () => {
  const raw = Buffer.from(JSON.stringify(rawConfig()), 'utf8');
  const loaded = __test.loadConfig('ignored', 'preview', { readFileSync: () => raw });
  assert.match(loaded.configDigest, /^[0-9a-f]{64}$/u);
  assert.throws(() => __test.loadConfig('ignored', 'preview', { readFileSync: () => Buffer.from('{') }), /invalid_json/);
  assert.throws(() => __test.loadConfig('ignored', 'preview', { readFileSync: () => Buffer.alloc(131073) }), /config_too_large/);
  const production = rawConfig();
  production.environments = { production: production.environments.preview };
  production.environments.production.targets.primary.base_url = '${PRODUCTION_BASE_URL}';
  const productionBytes = Buffer.from(JSON.stringify(production), 'utf8');
  assert.throws(() => __test.loadConfig('ignored', 'production', { readFileSync: () => productionBytes }), /production_base_url_secret_missing/);
  withEnv({ PRODUCTION_BASE_URL: 'https://production.example.com/' }, async () => {
    assert.equal(__test.loadConfig('ignored', 'production', { readFileSync: () => productionBytes }).primaryOrigin, 'https://production.example.com:443');
  });
});

test('missing production base URL secret blocks with the renamed code before any network request', async () => {
  const configPath = path.resolve(path.dirname(fileURLToPath(import.meta.url)), 'verification-config.json');
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'release-verification-'));
  try {
    await withEnv({ ...baseEnv, TARGET_ENVIRONMENT: 'production', VERIFICATION_CONFIG: configPath, OUTPUT_DIR: directory, PRODUCTION_BASE_URL: undefined }, async () => {
      const networkCalls = [];
      const report = await runVerifyMode({
        performRequest: async (url, method) => { networkCalls.push(`${method} ${url}`); return successTarget(url, method); },
        now: () => Date.parse('2026-08-26T12:00:00Z'), sleep: async () => {},
      });
      assert.equal(report.overall, 'blocked');
      assert.equal(report.code, 'config:production_base_url_secret_missing');
      assert.equal(report.management_eligible, false);
      assert.deepEqual(networkCalls, []);
      assert.equal(report.budgets.requests_used, 0);
      assert.equal(fs.existsSync(path.join(directory, 'report.json')), true);
      assert.equal(fs.existsSync(path.join(directory, 'junit.xml')), true);
    });
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});

test('resolved production base URL never reaches emitted evidence or the management payload', async () => {
  const configPath = path.resolve(path.dirname(fileURLToPath(import.meta.url)), 'verification-config.json');
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'release-verification-'));
  try {
    await withEnv({ ...baseEnv, TARGET_ENVIRONMENT: 'production', VERIFICATION_CONFIG: configPath, OUTPUT_DIR: directory, PRODUCTION_BASE_URL: 'https://production.example.com/' }, async () => {
      const report = await runVerifyMode({
        performRequest: async (url, method) => String(url).includes('/wp-content/') ? response(200, 'Stable tag: 1.2.3\n') : successTarget(url, method),
        now: () => Date.parse('2026-08-26T12:00:00Z'), sleep: async () => {},
      });
      assert.equal(report.overall, 'pass');
      const payload = parseManagementPayload(encode(__test.managementPayload(report)));
      assert.equal(payload.management_eligible, true);
      const emitted = fs.readdirSync(directory).map((name) => fs.readFileSync(path.join(directory, name), 'utf8')).join('\n');
      assert.doesNotMatch(emitted, /production\.example\.com/u);
      assert.doesNotMatch(JSON.stringify(payload), /production\.example\.com/u);
    });
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});

test('authenticated verification accepts bounded credentials without exposing them', async () => {
  await withEnv({ ...baseEnv, TARGET_BASIC_AUTH_USERNAME: 'user', TARGET_BASIC_AUTH_PASSWORD: 'private-password' }, async () => {
    const report = await verify(validated({ basicAuth: true }), {
      now: () => Date.parse('2026-08-26T12:00:00Z'), sleep: async () => {}, provenance: provenanceClaims,
      performRequest: async (url, method, context) => {
        assert.deepEqual(context.auth, { username: 'user', password: 'private-password' });
        return successTarget(url, method);
      },
      fs: { mkdirSync() {}, writeFileSync() {}, appendFileSync() {} },
    });
    assert.equal(report.overall, 'pass');
    assert.doesNotMatch(JSON.stringify(report), /private-password|"username"/u);
  });
});

test('ordering uses run, attempt, completion time and stable semantic precedence', () => {
  const base = management();
  const laterAttempt = { ...base, run_attempt: '2' };
  const laterTime = { ...base, completed_at: '2026-08-26T12:00:01.000Z' };
  assert.equal(__test.compareOrder(laterAttempt, base), 1);
  assert.equal(__test.compareOrder(laterTime, base), 1);
  assert.equal(__test.compareOrder(base, base), 0);
  assert.equal(__test.newerStableVersion('1.2.2', '1.2.3'), false);
  assert.equal(__test.newerStableVersion('1.2.3', '1.2.3'), false);
});

test('URL, path, identity and collection validation reject every unsafe declarative shape', () => {
  const mutations = [
    (copy) => { copy.environments.preview.targets.primary.base_url = 'http://public.example.com/base/'; },
    (copy) => { copy.environments.preview.targets.primary.base_url = 'https://user:pass@public.example.com/base/'; },
    (copy) => { copy.environments.preview.targets.primary.base_url = 'https://public.example.com/base/?x=1'; },
    (copy) => { copy.environments.preview.targets.primary.base_url = 'https://localhost/base/'; },
    (copy) => { copy.environments.preview.targets.primary.base_url = 'https://public.example.com:22/base/'; },
    (copy) => { copy.environments.preview.targets.primary.base_url = 'https://public.example.com/base/%2e%2e/'; },
    (copy) => { copy.environments.preview.checks[0].path = '//evil.example/'; },
    (copy) => { copy.environments.preview.checks[0].path = '/x?secret=1'; },
    (copy) => { copy.environments.preview.checks[0].path = '/%2e%2e/x'; },
    (copy) => { copy.environments.preview.checks[0].id = 'Bad ID'; },
    (copy) => { copy.environments.preview.checks[0].target = 'missing'; },
    (copy) => { copy.environments.preview.checks[0].type = 'admin'; },
    (copy) => { copy.environments.preview.checks[0].required = 'true'; },
    (copy) => { copy.environments.preview.checks.push(structuredClone(copy.environments.preview.checks[0])); },
    (copy) => { copy.environments.preview.version_sources.push(structuredClone(copy.environments.preview.version_sources[0])); },
    (copy) => { copy.environments.preview.allowed_redirects = [{ from: 'https://public.example.com/base/a', to: 'https://cdn.example.com/assets/a' }, { from: 'https://public.example.com/base/a', to: 'https://cdn.example.com/assets/b' }]; },
    (copy) => { copy.provenance.mode = 'unsupported'; },
    (copy) => { copy.provenance.release_asset_name = 'plugin.zip'; },
    (copy) => { copy.schema_version = 3; },
    (copy) => { copy.environments = {}; },
    (copy) => { copy.limits.evidence_reserve_ms = copy.limits.phase_timeout_ms; },
  ];
  for (const mutate of mutations) { const copy = rawConfig(); mutate(copy); assert.throws(() => validateConfig(copy, 'preview')); }
  assert.throws(() => validateConfig(rawConfig(), 'missing'), /selected_environment_not_declared/);
  assert.throws(() => validateConfig(rawConfig(), 'bad\nenvironment'), /selected_environment_invalid/);
});

test('special-use IPv4 ranges and malformed addresses remain non-public-unicast', () => {
  for (const address of ['0.0.0.0', '100.64.0.1', '172.16.0.1', '192.0.0.1', '192.0.2.1', '192.31.196.1', '192.52.193.1', '192.88.99.1', '192.175.48.1', '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '999.1.1.1', 'not-an-ip']) assert.equal(isUnsafeIp(address), true, address);
});

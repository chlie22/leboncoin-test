export const meta = {
  name: 'architecture-audit',
  description: 'Audit the FizzBuzz API against docs/conception.md zone by zone in parallel, then try to refute every blocking finding',
  whenToUse: 'Broad audit after several plan steps or before delivery. Not for a single change: use the arch-reviewer agent.',
  phases: [
    { title: 'Audit', detail: 'one agent per zone of the spec' },
    { title: 'Verify', detail: 'one skeptic per blocking finding' },
  ],
}

// Zones follow the sections of docs/conception.md. A zone whose paths do not exist yet is skipped, not audited.
const ZONES = [
  {
    key: 'layers',
    agentType: 'arch-reviewer',
    paths: ['src/', 'deptrac.yaml'],
    prompt: 'Audit the whole src/ tree, not a diff, against the hexagonal conventions: run Deptrac through the lint target when it can run, then follow the architecture-review checklist.',
  },
  {
    key: 'persistence',
    paths: ['src/FizzBuzz/Infrastructure/Persistence/', 'src/FizzBuzz/Infrastructure/Cli/', 'migrations/', 'config/packages/doctrine.yaml'],
    prompt: 'Audit the statistics storage against docs/conception.md §6.2 to §6.6: schema and indexes; record transaction (UPSERT as first statement, threshold read once, UPDATE ... WHERE id IN eviction, log rows deleted before stats rows); read query (separate max and min subqueries, window_count); per-connection pragmas in DBAL middlewares; startup window application in BEGIN IMMEDIATE.',
  },
  {
    key: 'http',
    paths: ['src/FizzBuzz/Infrastructure/Api/', 'src/Shared/Infrastructure/Http/', 'config/packages/framework.yaml'],
    prompt: 'Audit the HTTP layer against docs/conception.md §3.3 to §4.4 and §5.7, and against docs/openapi.yaml: MapQueryString failure status 400; NotNull and Range on integers; NotBlank, Length and a Regex with the strict end-of-string anchor on strings; HEAD neither generating nor counting; JSON encoded once with JSON_UNESCAPED_UNICODE; problem+json errors; degraded mode responses; no Cache-Control set by PHP.',
  },
  {
    key: 'runtime',
    paths: ['docker/', 'Dockerfile', 'compose.yaml'],
    prompt: 'Audit the runtime against docs/conception.md §7 and §8.1: Nginx quotas and bursts; real_ip; access log without query string; limit_req_log_level; internal /_errors/ locations instead of named locations; headers.conf re-included wherever add_header is redeclared; Cache-Control only from Nginx; /healthz allow and deny; FPM timeout below the Nginx timeout; startup sequence (migrations, apply-window, exec php-fpm); var/data ownership; non-root users.',
  },
  {
    key: 'tests',
    paths: ['tests/'],
    prompt: 'Audit the test suite against docs/conception.md §9.1: every level exists; the shared contract suite runs against both adapters; the §3.3 functional matrix is covered; query plans and window reduction are tested; smoke tests cover quotas, Nginx errors, headers and logs. Report a missing case on the closest existing test file, or on line 1 of the file that should exist.',
  },
]

const FINDINGS_SCHEMA = {
  type: 'object',
  required: ['zoneExists', 'findings'],
  properties: {
    zoneExists: { type: 'boolean' },
    findings: {
      type: 'array',
      items: {
        type: 'object',
        required: ['file', 'line', 'severity', 'rule', 'fix'],
        properties: {
          file: { type: 'string' },
          line: { type: 'number' },
          severity: { type: 'string', enum: ['blocking', 'discuss', 'note'] },
          rule: { type: 'string' },
          fix: { type: 'string' },
        },
      },
    },
  },
}

const VERDICT_SCHEMA = {
  type: 'object',
  required: ['refuted', 'reason'],
  properties: {
    refuted: { type: 'boolean' },
    reason: { type: 'string' },
  },
}

const results = await pipeline(
  ZONES,
  (zone) =>
    agent(
      `${zone.prompt}

Zone paths: ${zone.paths.join(', ')}. First check whether these paths exist. If none of them exists yet (the plan step is not implemented), return zoneExists=false with no findings. Every finding needs a file, a line, the rule with the spec section it comes from, and the fix. Read-only: never edit a file.`,
      {
        label: `audit:${zone.key}`,
        phase: 'Audit',
        schema: FINDINGS_SCHEMA,
        ...(zone.agentType ? { agentType: zone.agentType } : {}),
      }
    ),
  (result, zone) => {
    if (!result || !result.zoneExists) {
      return { zone: zone.key, skipped: true, refuted: 0, findings: [] }
    }
    const blocking = result.findings.filter((f) => f.severity === 'blocking')
    const rest = result.findings.filter((f) => f.severity !== 'blocking')
    if (!blocking.length) {
      return { zone: zone.key, skipped: false, refuted: 0, findings: rest }
    }
    return parallel(
      blocking.map((f, i) => () =>
        agent(
          `Try to REFUTE this finding. Read ${f.file} around line ${f.line} yourself, and the section of docs/conception.md it relies on. Claim: "${f.rule}". Refuted means the code does not violate the rule, the rule misreads the spec, or the spec documents the deviation. Default to refuted=true when uncertain.`,
          { label: `verify:${zone.key}:${i + 1}`, phase: 'Verify', schema: VERDICT_SCHEMA, effort: 'high' }
        ).then((v) => ({ ...f, refuted: v ? v.refuted !== false : true, why: v ? v.reason : 'verifier unavailable' }))
      )
    ).then((verdicts) => {
      const judged = verdicts.filter(Boolean)
      return {
        zone: zone.key,
        skipped: false,
        refuted: judged.filter((f) => f.refuted).length,
        findings: [...judged.filter((f) => !f.refuted), ...rest],
      }
    })
  }
)

const zones = results.filter(Boolean)
const failed = ZONES.length - zones.length
const skipped = zones.filter((z) => z.skipped).map((z) => z.zone)
if (failed) log(`${failed} zone(s) failed to complete and are NOT covered by this audit`)
if (skipped.length) log(`Not implemented yet, skipped: ${skipped.join(', ')}`)

const all = zones.flatMap((z) => z.findings.map((f) => ({ ...f, zone: z.zone })))
const refuted = zones.reduce((n, z) => n + z.refuted, 0)
const blocking = all.filter((f) => f.severity === 'blocking')

log(`${blocking.length} confirmed blocking, ${refuted} refuted, ${all.length - blocking.length} to discuss or note`)

return {
  skipped,
  failedZones: failed,
  blocking,
  discuss: all.filter((f) => f.severity === 'discuss'),
  notes: all.filter((f) => f.severity === 'note'),
}

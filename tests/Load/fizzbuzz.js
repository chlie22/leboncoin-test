// k6 load script for the FizzBuzz API (docs/conception.md §9.4).
// Scenarios: nominal (blocking thresholds), worst, ramp, contention, quotas.
// Run via the official grafana/k6 image against the prod stack through Nginx.
import http from 'k6/http';
import { check, sleep } from 'k6';
import { SharedArray } from 'k6/data';
import { Counter } from 'k6/metrics';

const status200 = new Counter('status_200');
const status429 = new Counter('status_429');
const statusOther = new Counter('status_other');

const BASE_URL = __ENV.BASE_URL || 'http://nginx:8080';
const SCENARIO = __ENV.SCENARIO || 'nominal';

const WORDS = new SharedArray('words', () => [
    'fizz', 'buzz', 'foo', 'bar', 'alpha', 'beta', 'gamma', 'delta',
    'one', 'two', 'red', 'blue', 'left', 'right', 'up', 'down',
]);

function randomInt(min, max) {
    return Math.floor(Math.random() * (max - min + 1)) + min;
}

function pickWord() {
    return WORDS[randomInt(0, WORDS.length - 1)];
}

function nominalQuery() {
    const int1 = randomInt(1, 20);
    let int2 = randomInt(1, 20);
    if (int2 === int1) {
        int2 = int1 === 20 ? 19 : int1 + 1;
    }
    const limit = randomInt(1, 100);
    return `int1=${int1}&int2=${int2}&limit=${limit}&str1=${pickWord()}&str2=${pickWord()}`;
}

function worstQuery() {
    const str = 'x'.repeat(50);
    return `int1=3&int2=5&limit=10000&str1=${str}&str2=${str}`;
}

function getQuery() {
    if (SCENARIO === 'worst') {
        return worstQuery();
    }
    return nominalQuery();
}

function expectedOk(res) {
    // Contention may still return 200 (degraded recording); quotas expect some 429.
    if (SCENARIO === 'quotas') {
        return res.status === 200 || res.status === 429;
    }
    return res.status === 200;
}

const scenarios = {
    nominal: {
        executor: 'constant-arrival-rate',
        rate: 10,
        timeUnit: '1s',
        duration: '2m',
        preAllocatedVUs: 20,
        maxVUs: 50,
    },
    worst: {
        executor: 'constant-arrival-rate',
        rate: 2,
        timeUnit: '1s',
        duration: '1m',
        preAllocatedVUs: 5,
        maxVUs: 20,
    },
    ramp: {
        executor: 'ramping-arrival-rate',
        startRate: 10,
        timeUnit: '1s',
        preAllocatedVUs: 30,
        maxVUs: 100,
        stages: [
            { target: 10, duration: '30s' },
            { target: 20, duration: '30s' },
            { target: 40, duration: '30s' },
            { target: 60, duration: '30s' },
            { target: 10, duration: '15s' },
        ],
    },
    contention: {
        executor: 'constant-arrival-rate',
        rate: 10,
        timeUnit: '1s',
        duration: '1m',
        preAllocatedVUs: 20,
        maxVUs: 50,
    },
    quotas: {
        // Faster than production per-IP (1r/s, burst 2) so 429s appear.
        executor: 'constant-arrival-rate',
        rate: 5,
        timeUnit: '1s',
        duration: '20s',
        preAllocatedVUs: 10,
        maxVUs: 20,
    },
};

if (!scenarios[SCENARIO]) {
    throw new Error(`Unknown SCENARIO="${SCENARIO}". Use nominal|worst|ramp|contention|quotas.`);
}

export const options = {
    scenarios: {
        [SCENARIO]: scenarios[SCENARIO],
    },
    // p50 (= med), p95 and p99 are required for the README deliverable (§9.4).
    summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'p(99)'],
    thresholds: SCENARIO === 'nominal'
        ? {
            http_req_duration: ['p(95)<50'],
            http_req_failed: ['rate<0.01'],
        }
        : {},
};

export default function () {
    const url = `${BASE_URL}/v1/fizzbuzz?${getQuery()}`;
    const res = http.get(url, {
        tags: { scenario: SCENARIO },
        timeout: '30s',
    });

    if (res.status === 200) {
        status200.add(1);
    } else if (res.status === 429) {
        status429.add(1);
    } else {
        statusOther.add(1);
    }

    check(res, {
        'status is expected': (r) => expectedOk(r),
    });

    if (SCENARIO === 'quotas') {
        // Keep VUs from hammering the leaky bucket beyond the arrival rate.
        sleep(0.05);
    }
}

import { boolEnv, environmentOverrideNames, stringEnv } from './env.js';
import { settings } from '../config/settings.js';

const ANSI = {
  reset: '\u001b[0m',
  bold: '\u001b[1m',
  green: '\u001b[32m',
  red: '\u001b[31m',
  yellow: '\u001b[33m',
  cyan: '\u001b[36m',
};

function metric(data, name) {
  return data.metrics && data.metrics[name] ? data.metrics[name] : { values: {} };
}

function value(data, name, key, fallback = 0) {
  const candidate = metric(data, name).values[key];
  return typeof candidate === 'number' && Number.isFinite(candidate) ? candidate : fallback;
}

function count(data, name) {
  return value(data, name, 'count', 0);
}

function rate(data, name) {
  return value(data, name, 'rate', 0);
}

function number(valueToFormat, digits = 2) {
  return Number(valueToFormat || 0).toFixed(digits);
}

function integer(valueToFormat) {
  return String(Math.round(valueToFormat || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function percent(valueToFormat) {
  return `${number((valueToFormat || 0) * 100, 2)}%`;
}

function milliseconds(valueToFormat) {
  return `${number(valueToFormat || 0, 2)} ms`;
}

function seconds(millisecondsValue) {
  return `${number((millisecondsValue || 0) / 1000, 2)} s`;
}

function failedThresholds(data) {
  const failures = [];
  Object.keys(data.metrics || {}).forEach((metricName) => {
    const thresholds = data.metrics[metricName].thresholds || {};
    Object.keys(thresholds).forEach((expression) => {
      if (thresholds[expression].ok === false) {
        const match = expression.match(/^([^<>=]+)\s*(<=|>=|==|<|>)\s*(.+)$/);
        const actual = match ? value(data, metricName, match[1].trim(), NaN) : NaN;
        failures.push({ metricName, expression, actual });
      }
    });
  });
  return failures;
}

function formatActual(item) {
  if (!Number.isFinite(item.actual)) return 'n/a';
  if (item.metricName.includes('duration')) return milliseconds(item.actual);
  if (item.expression.trim().startsWith('rate')) return percent(item.actual);
  return number(item.actual, 2);
}

function latencyLines(data, latencyMetrics) {
  const lines = [];
  latencyMetrics.forEach(({ label, metricName }) => {
    const values = metric(data, metricName).values || {};
    if (typeof values.count === 'number' && values.count === 0) return;
    if (!Object.keys(values).length) return;
    lines.push(
      `  ${label.padEnd(18)} avg ${milliseconds(values.avg)} | p90 ${milliseconds(values['p(90)'])} | p95 ${milliseconds(values['p(95)'])} | p99 ${milliseconds(values['p(99)'])} | max ${milliseconds(values.max)}`,
    );
  });
  return lines;
}

function color(enabled, code, text) {
  return enabled ? `${code}${text}${ANSI.reset}` : text;
}

export function summaryHandler({
  testName,
  title,
  profile,
  target,
  workload,
  runId,
  latencyMetrics,
  kind,
  thresholdDescription,
}) {
  const noColor = boolEnv('NO_COLOR', settings.report.noColor);
  const disableFile = boolEnv('DISABLE_SUMMARY_FILE', !settings.report.saveJson);
  const summaryPath = stringEnv('SUMMARY_PATH', `${settings.report.outputDirectory}/${runId}.json`);

  return function handleSummary(data) {
    const failures = failedThresholds(data);
    const passed = failures.length === 0;
    const overrides = environmentOverrideNames();
    const tty = data.state && data.state.isStdOutTTY;
    const colors = !noColor && tty;
    const status = passed
      ? color(colors, ANSI.green, 'PASS')
      : color(colors, ANSI.red, 'FAIL');
    const output = [];

    output.push('');
    output.push(color(colors, ANSI.bold + ANSI.cyan, '════════════════════════════════════════════════════════════════════'));
    output.push(color(colors, ANSI.bold, `  Soom Performance Report — ${title}`));
    output.push(color(colors, ANSI.bold + ANSI.cyan, '════════════════════════════════════════════════════════════════════'));
    output.push(`  Result         : ${status}`);
    output.push(`  Profile       : ${profile}`);
    output.push(`  Target        : ${target}`);
    output.push(`  Workload       : ${workload}`);
    output.push(`  Test duration  : ${seconds(data.state ? data.state.testRunDurationMs : 0)}`);
    output.push(`  Run ID        : ${runId}`);
    output.push(`  Config source  : settings.js${overrides.length ? ' + environment overrides' : ''}`);
    output.push(`  Env overrides  : ${overrides.length ? overrides.join(', ') : 'none'}`);
    output.push('');
    output.push(color(colors, ANSI.bold, 'Load execution'));
    output.push(`  Iterations     : ${integer(count(data, 'iterations'))}`);
    const loadHttpMetric = metric(data, 'http_reqs{phase:load}').values.count === undefined
      ? 'http_reqs'
      : 'http_reqs{phase:load}';
    output.push(`  Load requests  : ${integer(count(data, loadHttpMetric))} (${number(rate(data, loadHttpMetric))} actual req/s)`);
    output.push(`  Max VUs        : ${integer(value(data, 'observed_active_vus', 'max', value(data, 'observed_active_vus', 'value', 0)))}`);
    output.push(`  Dropped iters  : ${integer(count(data, 'dropped_iterations'))}`);
    output.push(`  Checks success : ${percent(rate(data, 'checks'))}`);
    output.push(`  HTTP failures  : ${percent(rate(data, 'http_req_failed{phase:load}'))}`);
    output.push('');
    output.push(color(colors, ANSI.bold, 'Latency by endpoint'));
    const latency = latencyLines(data, latencyMetrics);
    output.push(...(latency.length ? latency : ['  No load samples were recorded.']));
    output.push('');

    if (kind === 'home') {
      const responses = count(data, 'home_responses');
      const ok = count(data, 'home_status_200');
      output.push(color(colors, ANSI.bold, 'Home results'));
      output.push(`  Responses      : ${integer(responses)}`);
      output.push(`  Status 200     : ${integer(ok)}`);
      output.push(`  Success rate   : ${percent(responses ? ok / responses : 0)}`);
      output.push(`  Contract errors: ${percent(rate(data, 'home_request_failed'))}`);
    } else {
      const attempts = count(data, 'auction_bid_attempts');
      const accepted = count(data, 'auction_bid_accepted');
      const contention = count(data, 'auction_bid_contention');
      const limited = count(data, 'auction_rate_limited');
      const unexpected = count(data, 'auction_unexpected_errors');
      output.push(color(colors, ANSI.bold, 'Auction bidding results'));
      output.push(`  Attempts       : ${integer(attempts)}`);
      output.push(`  Accepted       : ${integer(accepted)} (${percent(attempts ? accepted / attempts : 0)})`);
      output.push(`  Contention 422 : ${integer(contention)} (${percent(attempts ? contention / attempts : 0)})`);
      output.push(`  Rate limit 429 : ${integer(limited)}`);
      output.push(`  Unexpected     : ${integer(unexpected)} (${percent(attempts ? unexpected / attempts : 0)})`);
      output.push(`  Flow failures  : ${percent(rate(data, 'auction_flow_failed'))}`);
    }

    output.push('');
    output.push(color(colors, ANSI.bold, 'Quality gates'));
    output.push(`  ${thresholdDescription}`);
    if (passed) {
      output.push(color(colors, ANSI.green, '  All thresholds passed.'));
    } else {
      failures.forEach((failure) => {
        output.push(color(
          colors,
          ANSI.red,
          `  ✗ ${failure.metricName}: actual=${formatActual(failure)}, required ${failure.expression}`,
        ));
      });
    }

    output.push('');
    const dropped = count(data, 'dropped_iterations');
    const limited = count(data, 'auction_rate_limited');
    if (kind === 'auction' && limited > 0) {
      output.push(color(colors, ANSI.yellow, 'INVALID LOAD: HTTP 429 was observed. This run measured the rate limiter, not bidding capacity.'));
    }
    if (dropped > 0) {
      output.push(color(colors, ANSI.yellow, 'GENERATOR SATURATED: iterations were dropped. Increase preAllocatedVUs or use a stronger load generator.'));
    }
    if (passed) {
      output.push(color(colors, ANSI.green, 'PASS: The system handled the configured workload within all quality gates.'));
    } else if (!(kind === 'auction' && limited > 0) && dropped === 0) {
      output.push(color(colors, ANSI.red, 'FAIL: At least one performance or reliability gate failed. Review the values above.'));
    }
    output.push('');

    const result = { stdout: `${output.join('\n')}\n` };
    if (!disableFile) {
      result[summaryPath] = JSON.stringify({
        metadata: {
          test_name: testName,
          title,
          profile,
          target,
          workload,
          run_id: runId,
          generated_at: new Date().toISOString(),
          thresholds: thresholdDescription,
        },
        ...data,
      }, null, 2);
    }
    return result;
  };
}

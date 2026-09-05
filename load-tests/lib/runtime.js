import exec from 'k6/execution';
import { Gauge } from 'k6/metrics';

const activeVUs = new Gauge('observed_active_vus');

export function recordActiveVUs() {
  activeVUs.add(exec.instance.vusActive);
}

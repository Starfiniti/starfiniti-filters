export interface RequestControlPolicy {
  maximumBodyConcurrency: number;
  maximumConcurrency: number;
  maximumTrackedClients: number;
  requestsPerMinute: number;
}

export class RequestControls {
  #bodyReads = 0;
  #inFlight = 0;
  #window = -1;
  readonly #requests = new Map<string, number>();

  public constructor(private readonly policy: RequestControlPolicy) {}

  public admit(client: string, now = Date.now()): boolean {
    const window = Math.floor(now / 60000);
    if (window !== this.#window) {
      this.#window = window;
      this.#requests.clear();
    }
    const current = this.#requests.get(client);
    if (current === undefined && this.#requests.size >= this.policy.maximumTrackedClients) return false;
    const next = (current ?? 0) + 1;
    this.#requests.set(client, next);
    return next <= this.policy.requestsPerMinute;
  }

  public enterExecution(): (() => void) | undefined {
    if (this.#inFlight >= this.policy.maximumConcurrency) return undefined;
    this.#inFlight += 1;
    let active = true;
    return () => {
      if (!active) return;
      active = false;
      this.#inFlight -= 1;
    };
  }

  public enterBody(): (() => void) | undefined {
    if (this.#bodyReads >= this.policy.maximumBodyConcurrency) return undefined;
    this.#bodyReads += 1;
    let active = true;
    return () => {
      if (!active) return;
      active = false;
      this.#bodyReads -= 1;
    };
  }

  public get bodyReads(): number { return this.#bodyReads; }
  public get inFlight(): number { return this.#inFlight; }
  public get trackedClients(): number { return this.#requests.size; }
}

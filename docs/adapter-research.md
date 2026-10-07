# Adapter research: which agent frameworks to support

**Date:** 2026-10-07 · **Question:** which PHP/Laravel agent frameworks should get a guarded-tools adapter next?

## 1. Usage (Packagist, 2026-10-07)

| Package | Total downloads | Last 30 days | GitHub stars | Latest release | Notes |
|---|---|---|---|---|---|
| laravel/ai | 9,593,473 | 2,602,717 | 1,212 | v1.1.0 (2026-10-05) | Official SDK; **supported (Track B)** |
| prism-php/prism | 7,237,919 | 788,166 | 2,425 | v0.100.1 (2026-03-20) | Independent of laravel/ai; no release for ~7 months |
| neuron-core/neuron-ai | 1,266,940 | 205,269 | 2,124 | 4.1.5 (2026-10-07) | Framework-agnostic PHP; very active |
| maestroerror/laragent | 227,611 | 22,888 | 644 | 1.4.0 (2026-05-09) | Laravel agent framework |
| packstub/agents | 603 | 603 | 1 | 1.7.2 (2026-10-07) | **Supported (Track A)** |
| vizra/vizra-adk | 43,903 | 4,105 | 295 | — | Marked abandoned on Packagist |
| whilesmart/eloquent-agents, laragentic/agents, edulazaro/laragents | under 200 each | — | — | — | Too small |

`laravel/mcp` (7.6 M per month) and `openai-php/laravel` (1.0 M per month) are not agent frameworks: one is an MCP server, the other an API client.

## 2. Fit: can the guarantees be enforced?

| Framework | Tool shape | Intercept / wrap execution | Human approval | Visibility per user | Tool call id |
|---|---|---|---|---|---|
| laravel/ai | `Tool` class, `handle(Request)` | Yes (our base class owns `handle()`) | Yes (`Approvable`, needs a conversational agent) | Yes (agent `tools()`) | Yes (`Request::toolCallId()`) |
| Neuron AI | `Tool` class, `__invoke()`, `properties()` | Yes (our base class owns `__invoke()`); tool error handler | **Yes** (`approvalPolicy(array $inputs)`; the run is interrupted and resumed with `submitInputs()`) | **Yes** (`visible(bool)`) | Run key (`getRunKey()`); provider id not documented |
| Prism | Closure (`Tool::as()->using()`) or class with `__invoke()` | Class-based only; no documented hook | **No** | Only by which tools are passed | Not documented |
| LarAgent | Tool class, `#[Tool]` methods, facade | "Nearly everything is hookable"; tool-level hooks not documented | Not documented | Not documented | Not documented |
| packstub/agents | `AgentTool::run()` | Yes | Yes (packstub's own) | Yes (`shouldRegister()`) | No (derived key) |

## 3. Recommendation

1. **laravel/ai stays the main target.** It is the official SDK and the largest by far (2.6 M per month). Lead the README with it. Test against **v1.1.0** (released 2026-10-05; CI still pins 1.0.1).
2. **Next adapter: Neuron AI.** Second-largest active agent framework (205 k per month, released today), and it has all three hooks the guarantees need: a class with `__invoke()` the base class can own, a built-in approval interrupt for write tools (W1), and per-tool visibility (exposure). Full read and write parity looks possible. Caveat: Neuron is framework-agnostic; our pipeline uses Laravel (DB, Validator, Gate), so the adapter targets Neuron **inside Laravel apps**.
3. **Prism: read tools only, later.** Large install base (788 k per month), but no approval and no documented interception point, and no release since March 2026. A read-only adapter is possible through a class-based `__invoke()`; write tools could not offer W1 without the app's own confirmation step.
4. **LarAgent: wait.** Smaller (23 k per month) and its tool hooks are not documented; revisit if users ask.
5. **packstub: keep, do not expand.** 603 downloads in total. The adapter costs little to maintain and found a real security issue, but it is not where the users are.

## 4. Open questions before building the Neuron adapter

- How Neuron passes call ids and whether a retried run reuses them (needed for W4).
- How the app's current user and workspace reach a Neuron tool (constructor injection is the documented way); `Guarded::run()` should work the same.
- Whether Neuron's tool error handler or our `__invoke()` sees exceptions first.

## Sources

- Packagist package pages and API (`packagist.org/packages/<name>.json`, `repo.packagist.org/p2/<name>.json`), read 2026-10-07.
- [Prism: tools and function calling](https://prismphp.com/core-concepts/tools-function-calling.html), [Prism releases](https://github.com/prism-php/prism/releases)
- [Neuron AI: tools](https://docs.neuron-ai.dev/agent/tools)
- [LarAgent repository](https://github.com/MaestroError/LarAgent)
- Search results for Laravel agent packages: [packstub/agents](https://packagist.org/packages/packstub/agents), [whilesmart/eloquent-agents](https://packagist.org/packages/whilesmart/eloquent-agents), [laragentic/agents](https://root.packagist.org/packages/laragentic/agents), [edulazaro/laragents](https://root.packagist.org/packages/edulazaro/laragents)
- Community comparison: [r/PHPhelp: Neuron AI or LarAgent or Prism](https://redlib.hbubli.cc/r/PHPhelp/comments/1kunz0u/neuron_ai_or_laragent_or_prism)

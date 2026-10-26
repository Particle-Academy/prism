# Companion Packages

Add sessions, memory, scoped files, remote tools or telemetry to a Prism application with companion packages. Install the packages you need alongside `particle-academy/prism`.

## Available packages

| Package | Use |
|---|---|
| [Harness](/companion-packages/harness) | Durable sessions, modes, tool authorization and subagents |
| [MCP](/companion-packages/mcp) | Connect remote MCP tools with trust policies and result controls |
| [Memory](/companion-packages/memory) | Store context and retrieve related memories within a token budget |
| [Workspace](/companion-packages/workspace) | Read and write files within a scoped storage directory |
| [Perplexity](https://github.com/Particle-Academy/prism-perplexity) | Use Perplexity endpoints outside the core provider API |
| [OpenTelemetry](/advanced/telemetry) | Export Prism telemetry as OpenTelemetry spans |

## Installation

All of these packages are available through Composer:

```bash
composer require particle-academy/prism-harness
composer require particle-academy/prism-mcp
composer require particle-academy/prism-memory
composer require particle-academy/prism-workspace
composer require particle-academy/prism-perplexity
composer require particle-academy/prism-opentelemetry
```

Install each package separately as needed. Follow its guide for migrations, configuration, credentials and authorization requirements.

## Combining packages

Memory can supply context to a Prism request, Workspace can supply file tools, and MCP can supply tools from a remote server. Harness manages sessions and tool execution; OpenTelemetry observes Prism calls. Check each package's requirements before composing integrations.

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
- On this Windows setup the `graphify` command is not on PATH: run it as `python -m graphify ...` (e.g. `python -m graphify query "<question>"`). Install with `pip install graphifyy`.
- Build/update the graph code-only and offline: `GRAPHIFY_BACKEND=none python -m graphify update .` (the `.graphifyignore` excludes dumps, data .sql, .env, vendor).
- Cross-repo business context (VPS, crons, daily processes, tables, rules): `docs/CONTEXTO_FINPAY.md`. Combined graph of the 3 repos: `AutomatizacionDeTransferenciasFinPay/graphify-out/finpay-merged-graph.json`.

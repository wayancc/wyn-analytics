# Changelog

All notable changes are listed here. The project follows [Semantic Versioning](https://semver.org).

## [0.1.0] - 2026-10-29

First release.

### Added

- Tracking script (`/wyn.js`, about 1.8 KB gzipped): pageviews, single-page apps, engagement time, custom events with properties, optional outbound links and file downloads, and Wyn Forms submissions.
- Cookieless visitor counting with daily-rotating salts; no IP addresses stored; Global Privacy Control respected by default; `#wyn-ignore` opt-out; bot, headless-browser and excluded-IP filtering.
- Dashboard: visitors, visits, pageviews, bounce rate, visit duration and visitors now, each compared with the previous period; pages, sources, UTM, countries, devices, browsers, OS and screen sizes; filters; goals with conversion rates; events with properties.
- Several sites, each with its own time zone.
- Daily rollups kept forever, detailed data kept for 30 days (configurable). Rollups run from cron, a web-cron URL, a worker, or automatically when the dashboard opens.
- Public stats page per site, with an optional password.
- JSON API with read-only tokens; CSV export.
- Countries from Cloudflare or the free DB-IP database (`geo:update`).
- Bold and Neutral themes, light and dark, WCAG AA contrast, chart text summaries and table views.
- CLI: `run`, `work`, `prune`, `geo:update`, `site:add`, `sites`, `export`, `user:password`, `serve`.
- Dependency-free test suite and load benchmark.

[0.1.0]: https://github.com/wayancc/wyn-analytics/releases/tag/v0.1.0

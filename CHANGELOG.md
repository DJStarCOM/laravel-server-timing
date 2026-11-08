# Changelog

All notable changes to `laravel-server-timing` will be documented in this file

## Unreleased

- Added support for Laravel 9, 10, 11, 12 and 13.
- **Changed header output.** Metrics are now joined with `implode(', ')`, so the
  header no longer ends in a trailing `", "` that produced an empty final list
  element; and a metric without a duration is rendered as `name;desc="Name"`
  rather than `name;desc="Name";`, which left a dangling parameter separator.
  Both forms were invalid per RFC 8941.
- **Dropped** PHP 7.2–7.4 and Laravel 5.8/6/7 from `require`. They could not be
  tested: no installable `orchestra/testbench` covers them. Use an earlier tag.
- Removed `.travis.yml`; the matrix it described can no longer be installed.

## 0.0.3

- Added support for laravel 8

## 0.0.2

- Added config and laravel publisher

## 0.0.1

- initial release

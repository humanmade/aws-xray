# Change Log

## HEAD
- Fix all requests showing as error ([#23](https://github.com/humanmade/aws-xray/pull/23))
- Report errors when segments are too big for sending over UDP ([#26](https://github.com/humanmade/aws-xray/pull/26))
- Track all AWS SDK requests in XRay ([#28](https://github.com/humanmade/aws-xray/pull/28))

## 1.1.1 - 2019-02-04

- `SELECT` Queries with leading whitespace cause Trace ID to be added

## 1.1.0 - 2019-01-24

- Use `register_shutdown_function` to capture fatal errors
- Update file loading and organization to allow loading early in the boot process. `inc/namespace.php` is to be loaded first, then `plugin.php` when WordPress is loaded.

## 1.0.3 - 2018-10-23

- Use local declaration of wp_debug_backtrace_summary

## 1.0.2 - 2018-10-12

- Track Remote Requests made via the WordPress HTTP API.

# Change Log

## 1.2.3 - 2019-12-09

- Only load JS when needed [#43](https://github.com/humanmade/aws-xray/pull/43)

## 1.2.2 - 2019-11-28

- Fix undefined HM_DEPLOYMENT_REVISION constant [#40](https://github.com/humanmade/aws-xray/pull/40)

## 1.2.1 - 2019-11-22

- Fix undefined HM_DEPLOYMENT_REVISION constant [#39](https://github.com/humanmade/aws-xray/pull/39)

## 1.2.0 - 2019-11-18

- Add Query Monitor Support [#36](https://github.com/humanmade/aws-xray/pull/36)
- Filter use of fastcgi_finish_request [#33](https://github.com/humanmade/aws-xray/pull/33)
- Redact password from POST requests, allow additional meta to be redacted via filters [#34](https://github.com/humanmade/aws-xray/pull/34)
- Fix composer.json license string [#38](https://github.com/humanmade/aws-xray/pull/38)

## 1.1.3 - 2019-05-13

- Use shutdown hook instead of `register_shutdown_runction` [#32](https://github.com/humanmade/aws-xray/pull/32)

## 1.1.2 - 2019-03-06

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

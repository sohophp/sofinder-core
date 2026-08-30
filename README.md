# SoFinder Core

Framework-independent file management, storage, metadata, security, image,
maintenance and value contracts for SoFinder.

This package supports PHP 8.2–8.5 and deliberately does not install Symfony,
Laravel, Slim or Mezzio. Install it directly for a headless/domain-only host:

```bash
composer require sohophp/sofinder-core:^1.1
```

Applications must supply explicit authorization and a PSR-14 event dispatcher.
Installing Core alone does not provide HTTP routes or the React browser UI.

Framework bridges normalize YAML or PHP configuration through
`SohoPHP\\SoFinder\\Configuration\\ConfigurationNormalizer`. Headless hosts may
call `normalize($config, $hostDefaults)` directly; host defaults should provide
runtime-specific cache, storage and secret paths while retaining the published
snake_case keys and security limits.

Documentation: <https://sofinder.sohophp.app/package-architecture>

License: MIT.

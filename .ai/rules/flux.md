---
paths:
  - '{config/blaze.php,resources/views/flux/**}'
---

# Flux

## Blaze is disabled because folding bakes translations into cached views
config/blaze.php defaults 'enabled' to false. With Blaze on, Flux components marked @blaze(fold: true) evaluate {{ __('...') }} inside their own templates at compile time, so labels like "Toggle sidebar" or "Close modal" were stored in storage/framework/views in whichever language compiled first, and English users saw Arabic. Do not re-enable Blaze unless every folded Flux template is localized per request. Side effect: Blade::render() tests of Flux inputs need View::share('errors', new ViewErrorBag).

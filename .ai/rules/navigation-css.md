---
paths:
  - '{app/Http/Middleware/SetLocale.php,app/Http/Controllers/SwitchLocaleController.php,resources/views/components/navigation/sidebar.blade.php,resources/css/*.css}'
---

# Navigation Css

## Language comes from users.locale, then session; RTL via html dir and logical classes
SetLocale (web middleware) applies users.locale, then session 'locale', then config app.locale (en), and sets Carbon's locale. POST route locale.update saves the choice on the user and in the session. Layouts set <html dir> from App\Enums\Locale::current()->direction(). Use logical Tailwind classes (ms/me/ps/pe/start/end/text-start/text-end), never left/right; flip directional icons with rtl:-scale-x-100 or rtl:[&_svg]:-scale-x-100. HR names use ->name / ->secondary_name, which follow the language (Arabic first in ar). Somar Sans (resources/fonts/somar-sans, weights 300-800) is the main font for both languages.

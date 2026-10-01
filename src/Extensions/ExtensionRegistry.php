<?php

namespace Pine\Commerce\Extensions;

use Closure;
use InvalidArgumentException;

/**
 * State behind the static extension API on Pine\Commerce\Commerce (a container singleton, so every application
 * instance – and every test – starts empty). Client code never uses this class directly: call the Commerce:: methods
 * from a service provider's boot() (or a theme's ThemeDefinition::boot()). docs/EXTENDING.md documents
 * every registry.
 */
class ExtensionRegistry
{
    /** @var array<string, class-string|object|null> code => gateway (null = removed) */
    protected array $gateways = [];

    /** @var list<array{pattern:string, calculator:class-string|object|Closure}> */
    protected array $shippingCalculators = [];

    /** @var list<Closure> admin route callbacks (Commerce::adminRoutes) not registered yet */
    protected array $pendingAdminRoutes = [];

    protected bool $adminRoutesOpen = false;

    /** @var array<string, array{group:array, sections:list<array>}> */
    protected array $settingsGroups = [];

    /** @var array<string, list<array>> group => extra sections for an existing group */
    protected array $settingsSections = [];

    /** @var array<string, array{meta:array, schema:array}> */
    protected array $pageTemplates = [];

    /** @var array<string, string> location key => label */
    protected array $menuLocations = [];

    /** @var array<string, array> */
    protected array $dashboardWidgets = [];

    /** @var array<string, class-string> order email key => mailable class */
    protected array $orderEmails = [];

    /** @var list<class-string|object> */
    protected array $importAdapters = [];

    /** @var list<class-string|object> */
    protected array $importSteps = [];

    /** @var array<string, array> key => normalised Scheduling\ClientTask definition */
    protected array $scheduledTasks = [];

    // ------------------------------------------------------------------ payments

    public function addGateway(string $code, string|object|null $gateway): void
    {
        if (! preg_match('/^[a-z0-9_-]+$/', $code)) {
            throw new InvalidArgumentException("Payment gateway code [{$code}] may only contain a-z, 0-9, _ and -.");
        }
        $this->gateways[$code] = $gateway;
    }

    /** @return array<string, class-string|object|null> */
    public function gateways(): array
    {
        return $this->gateways;
    }

    // ------------------------------------------------------------------ shipping

    public function addShippingCalculator(string $pattern, string|object $calculator): void
    {
        $this->shippingCalculators[] = ['pattern' => $pattern, 'calculator' => $calculator];
    }

    /** @return list<array{pattern:string, calculator:class-string|object|Closure}> in registration order (first match wins) */
    public function shippingCalculators(): array
    {
        return $this->shippingCalculators;
    }

    // ------------------------------------------------------------------ admin routes

    public function addAdminRoutes(Closure $routes): void
    {
        if ($this->adminRoutesOpen) {
            AdminRoutes::register($routes);

            return;
        }
        $this->pendingAdminRoutes[] = $routes;
    }

    /** Called once the application has booted: registers the queued callbacks; later ones register immediately. */
    public function flushAdminRoutes(): void
    {
        $this->adminRoutesOpen = true;
        foreach ($this->pendingAdminRoutes as $routes) {
            AdminRoutes::register($routes);
        }
        $this->pendingAdminRoutes = [];
    }

    // ------------------------------------------------------------------ settings

    /**
     * @param  array{label?:string, icon?:string, description?:string, admin?:bool, feature?:string}  $group
     * @param  list<array{title:string, description?:string, fields:list<array>}>  $sections
     */
    public function addSettingsGroup(string $key, array $group, array $sections): void
    {
        if (! preg_match('/^[a-z0-9_-]+$/', $key)) {
            throw new InvalidArgumentException("Settings group key [{$key}] may only contain a-z, 0-9, _ and -.");
        }
        foreach ($sections as $section) {
            $this->assertSection($section);
        }
        $this->settingsGroups[$key] = [
            'group' => $group + ['label' => ucfirst(str_replace(['-', '_'], ' ', $key)), 'icon' => 'cog-6-tooth', 'description' => '', 'route' => 'admin.settings.edit'],
            'sections' => array_values($sections),
        ];
    }

    /** Extra card of fields on an existing group (core or client). */
    public function addSettingsSection(string $group, array $section): void
    {
        $this->assertSection($section);
        $this->settingsSections[$group][] = $section;
    }

    /** @return array<string, array{group:array, sections:list<array>}> */
    public function settingsGroups(): array
    {
        return $this->settingsGroups;
    }

    /** @return list<array> */
    public function settingsSections(string $group): array
    {
        return $this->settingsSections[$group] ?? [];
    }

    protected function assertSection(array $section): void
    {
        if (empty($section['title']) || ! is_array($section['fields'] ?? null)) {
            throw new InvalidArgumentException('A settings section needs a title and a list of fields.');
        }
        foreach ($section['fields'] as $field) {
            if (! is_array($field) || empty($field['key']) || empty($field['label']) || empty($field['type'])) {
                throw new InvalidArgumentException('Every settings field needs key, label and type (see StoreSettings).');
            }
        }
    }

    // ------------------------------------------------------------------ content

    public function addPageTemplate(string $key, array $meta, array $schema): void
    {
        if (! preg_match('/^[a-z0-9-]+$/', $key)) {
            throw new InvalidArgumentException("Page template key [{$key}] may only contain a-z, 0-9 and -.");
        }
        $this->pageTemplates[$key] = ['meta' => $meta, 'schema' => $schema];
    }

    /** @return array<string, array{meta:array, schema:array}> */
    public function pageTemplates(): array
    {
        return $this->pageTemplates;
    }

    public function addMenuLocation(string $key, string $label): void
    {
        if (! preg_match('/^[a-z0-9_-]+$/', $key)) {
            throw new InvalidArgumentException("Menu location [{$key}] may only contain a-z, 0-9, _ and -.");
        }
        $this->menuLocations[$key] = $label;
    }

    /** @return array<string, string> */
    public function menuLocations(): array
    {
        return $this->menuLocations;
    }

    // ------------------------------------------------------------------ dashboard

    public function addDashboardWidget(string $key, array|string|object $widget): void
    {
        if (is_array($widget) && empty($widget['view'])) {
            throw new InvalidArgumentException("Dashboard widget [{$key}] needs a 'view'.");
        }
        $this->dashboardWidgets[$key] = is_array($widget)
            ? $widget + ['title' => null, 'subtitle' => null, 'data' => null, 'feature' => null, 'admin' => false, 'sort' => 100, 'wide' => false]
            : ['class' => $widget, 'sort' => 100, 'wide' => false, 'feature' => null, 'admin' => false];
    }

    public function removeDashboardWidget(string $key): void
    {
        unset($this->dashboardWidgets[$key]);
    }

    /** @return array<string, array> */
    public function dashboardWidgets(): array
    {
        return $this->dashboardWidgets;
    }

    // ------------------------------------------------------------------ orders

    public function setOrderEmail(string $key, string $mailable): void
    {
        $this->orderEmails[$key] = $mailable;
    }

    public function orderEmail(string $key): ?string
    {
        return $this->orderEmails[$key] ?? null;
    }

    // ------------------------------------------------------------------ scheduled tasks

    public function addScheduledTask(array $definition): void
    {
        $this->scheduledTasks[$definition['key']] = $definition;
    }

    /** @return array<string, array> key => Scheduling\ClientTask definition, in registration order */
    public function scheduledTasks(): array
    {
        return $this->scheduledTasks;
    }

    // ------------------------------------------------------------------ importer

    public function addImportAdapter(string|object $adapter): void
    {
        $this->importAdapters[] = $adapter;
    }

    public function addImportStep(string|object $step): void
    {
        $this->importSteps[] = $step;
    }

    /** @return list<class-string|object> */
    public function importAdapters(): array
    {
        return $this->importAdapters;
    }

    /** @return list<class-string|object> */
    public function importSteps(): array
    {
        return $this->importSteps;
    }
}

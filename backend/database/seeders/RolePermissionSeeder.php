<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The platform's authorisation matrix.
 *
 * Permissions are grouped by domain and named `<group>.<verb>`. Roles are
 * assigned permission sets rather than hard-coded checks, so an administrator
 * can retune a role in the console without a deploy — with the deliberate
 * exception of `super_admin`, which bypasses every gate.
 */
class RolePermissionSeeder extends Seeder
{
    /** @var array<string, list<string>> */
    private const PERMISSIONS = [
        'users' => ['users.view', 'users.create', 'users.update', 'users.delete', 'users.impersonate'],
        // Tenants themselves. Creating a company and setting what it is
        // entitled to are platform decisions, not tenant ones, so these are
        // reached only by super_admin ('*') and system_admin ('all_except:…')
        // and are deliberately absent from every company-level role below.
        'companies' => ['companies.view', 'companies.create', 'companies.update'],
        'roles' => ['roles.manage'],
        'stations' => ['stations.view', 'stations.create', 'stations.update', 'stations.delete', 'stations.verify'],
        'prices' => ['prices.view', 'prices.update', 'prices.moderate', 'prices.import'],
        'vehicles' => ['vehicles.view', 'vehicles.create', 'vehicles.update', 'vehicles.delete'],
        'fleet' => ['fleet.view', 'fleet.manage', 'fleet.reports', 'fleet.assign_drivers'],
        'drivers' => ['drivers.view', 'drivers.manage'],
        'expenses' => ['expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete'],
        'maintenance' => ['maintenance.view', 'maintenance.manage'],
        'fraud' => ['fraud.view', 'fraud.resolve'],
        // Seeing where a vehicle *is* and reconstructing where it *has been*
        // are different questions about a person's movements, so history is a
        // separate grant rather than something implied by the first.
        'devices' => ['devices.view', 'devices.manage', 'devices.location.view', 'devices.location.history'],
        'reports' => ['reports.view', 'reports.platform', 'station.reports'],
        'analytics' => ['analytics.view', 'analytics.platform'],
        'ai' => ['ai.use', 'ai.manage'],
        'audit' => ['audit.view'],
        'settings' => ['settings.manage'],
    ];

    /** @var array<string, array{label: string, level: int, permissions: list<string>|string}> */
    private const ROLES = [
        'super_admin' => [
            'label' => 'Super Administrator',
            'level' => 1,
            'permissions' => '*',
        ],
        'system_admin' => [
            'label' => 'System Administrator',
            'level' => 2,
            // Everything except the ability to mint other administrators.
            'permissions' => 'all_except:users.impersonate,roles.manage',
        ],
        'station_admin' => [
            'label' => 'Gas Station Administrator',
            'level' => 3,
            'permissions' => [
                'stations.view', 'stations.update', 'prices.view', 'prices.update',
                'station.reports', 'analytics.view', 'ai.use',
            ],
        ],
        'fleet_manager' => [
            'label' => 'Fleet Manager',
            'level' => 4,
            'permissions' => [
                'vehicles.view', 'vehicles.create', 'vehicles.update', 'vehicles.delete',
                'fleet.view', 'fleet.manage', 'fleet.reports', 'fleet.assign_drivers',
                'drivers.view', 'drivers.manage',
                'expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete',
                'maintenance.view', 'maintenance.manage',
                'fraud.view', 'fraud.resolve',
                'devices.view', 'devices.manage', 'devices.location.view', 'devices.location.history',
                'reports.view', 'analytics.view', 'prices.view', 'stations.view', 'ai.use',
            ],
        ],
        'company_manager' => [
            'label' => 'Company Manager',
            'level' => 4,
            'permissions' => [
                // Creates and edits people inside their own company. Deleting a
                // user and minting roles stay with platform administrators:
                // both are irreversible in ways a tenant admin should not own.
                'users.view', 'users.create', 'users.update',
                'vehicles.view', 'vehicles.create', 'vehicles.update',
                'fleet.view', 'fleet.manage', 'fleet.reports',
                'drivers.view', 'drivers.manage',
                'expenses.view', 'expenses.create', 'expenses.update',
                'maintenance.view', 'maintenance.manage',
                'fraud.view', 'reports.view', 'analytics.view',
                // Current position, deliberately without history: seeing the
                // fleet on a map now is an operational need; replaying a
                // driver's week is a different one, and needs granting.
                'devices.view', 'devices.location.view',
                'prices.view', 'stations.view', 'ai.use',
            ],
        ],
        'driver' => [
            'label' => 'Driver',
            'level' => 6,
            'permissions' => [
                'vehicles.view', 'expenses.view', 'expenses.create',
                // A driver registers and revokes their own handset. They get no
                // location permission: reporting is authorised by the device
                // registration, not by a permission to read other people.
                'devices.view', 'devices.manage',
                'maintenance.view', 'prices.view', 'stations.view', 'ai.use',
            ],
        ],
        'user' => [
            'label' => 'Registered User',
            'level' => 7,
            'permissions' => [
                'vehicles.view', 'vehicles.create', 'vehicles.update', 'vehicles.delete',
                'expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete',
                'maintenance.view', 'maintenance.manage',
                'prices.view', 'stations.view', 'reports.view', 'analytics.view', 'ai.use',
            ],
        ],
        /*
         * Read-only oversight. Sees the fleet, its vehicles, its alerts and the
         * reports; changes nothing.
         *
         * Deliberately without drivers.view — a viewer has no reason to read a
         * staff roster — and without any devices permission, so neither live
         * positions nor location history are reachable. Read-only here is the
         * absence of write permissions rather than a flag: no entry in this set
         * grants a mutation.
         */
        'viewer' => [
            'label' => 'Fleet Viewer',
            'level' => 8,
            'permissions' => [
                'fleet.view', 'vehicles.view', 'fraud.view',
                'reports.view', 'analytics.view',
                'prices.view', 'stations.view',
            ],
        ],
        'guest' => [
            'label' => 'Guest User',
            'level' => 9,
            'permissions' => ['prices.view', 'stations.view'],
        ],
    ];

    public function run(): void
    {
        Artisan::call('cache:clear');

        $all = [];

        foreach (self::PERMISSIONS as $group => $names) {
            foreach ($names as $name) {
                Permission::updateOrCreate(
                    ['name' => $name, 'guard_name' => 'api'],
                    ['group_name' => $group],
                );

                $all[] = $name;
            }
        }

        foreach (self::ROLES as $name => $definition) {
            $role = Role::updateOrCreate(
                ['name' => $name, 'guard_name' => 'api'],
                ['label' => $definition['label'], 'level' => $definition['level']],
            );

            $role->syncPermissions($this->resolvePermissions($definition['permissions'], $all));
        }

        app()['cache']->forget(config('permission.cache.key', 'spatie.permission.cache'));

        $this->command?->info(sprintf(
            'Seeded %d permissions across %d roles.',
            count($all),
            count(self::ROLES),
        ));
    }

    /**
     * @param list<string>|string $spec
     * @param list<string> $all
     * @return list<string>
     */
    private function resolvePermissions(array|string $spec, array $all): array
    {
        if ($spec === '*') {
            return $all;
        }

        if (is_string($spec) && str_starts_with($spec, 'all_except:')) {
            $excluded = explode(',', substr($spec, strlen('all_except:')));

            return array_values(array_diff($all, $excluded));
        }

        return (array) $spec;
    }
}

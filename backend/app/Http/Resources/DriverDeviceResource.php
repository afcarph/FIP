<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\User\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One handset belonging to a driver, as their setup page needs it.
 *
 * Deliberately not DeviceResource. That one carries `last_location` — the
 * device's most recent latitude and longitude — and this endpoint is reached
 * with `drivers.view`, which a company manager holds while deliberately
 * holding no location permission at all. Reusing it would have handed a
 * position to a role the product refuses it to, through a screen about
 * whether an app is installed.
 *
 * So the shape here is only what the question needs: which handset, running
 * what, last heard from when, and whether it has been pointed at a vehicle
 * yet. No coordinates, no push credential.
 *
 * The `@mixin` is how the property reads below are typed, rather than the
 * per-file `property.notFound` suppression the older resources in this folder
 * carry in phpstan.neon. It costs one line and keeps a mistyped column here a
 * build failure instead of a silent null.
 *
 * @mixin UserDevice
 */
class DriverDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_name' => $this->device_name,
            'platform' => $this->platform,
            'app_version' => $this->app_version,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'registered_at' => $this->created_at?->toIso8601String(),
            /*
             * The one field that separates "the app is on their phone" from
             * "it is reporting for a truck". Null is a normal state, not a
             * fault: registration never sets it, and it is filled in later
             * through the device PATCH once the driver has an assignment.
             */
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'plate_number' => $this->vehicle->plate_number,
            ] : null),
        ];
    }
}

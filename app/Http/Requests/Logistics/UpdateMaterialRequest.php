<?php

namespace App\Http\Requests\Logistics;

use App\Models\Material;
use Illuminate\Validation\Rule;

/** Same rules as creating — every editable field is required either way. */
class UpdateMaterialRequest extends StoreMaterialRequest
{
    /** A unit deactivated after this material was created stays valid for it. */
    protected function currentUnitIds(): array
    {
        return [$this->material()->unit_id];
    }

    /** Likewise its current category, even if deactivated since. */
    protected function categoryRule(): mixed
    {
        $current = $this->material()->material_category_id;

        return Rule::exists('material_categories', 'id')->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $current));
    }

    private function material(): Material
    {
        return $this->route('material');
    }
}

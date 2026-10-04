<?php

namespace App\Http\Requests\MasterData;

/** Same rules — StoreVendorRequest already ignores the edited row in its unique check. */
class UpdateVendorRequest extends StoreVendorRequest {}

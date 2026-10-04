<?php

namespace App\Http\Requests\MasterData;

/** Same rules — StoreUnitRequest already ignores the edited row in its unique check. */
class UpdateUnitRequest extends StoreUnitRequest {}

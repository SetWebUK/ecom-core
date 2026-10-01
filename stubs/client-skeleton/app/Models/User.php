<?php

namespace App\Models;

/**
 * The client's user model (config/auth.php, UserFactory, seeders and tests point here). All behaviour lives in the
 * pine/commerce core model; add client-only relations/methods here. Core code resolves the configured class via
 * \Pine\Commerce\Commerce::userModel().
 */
class User extends \Pine\Commerce\Models\User {}

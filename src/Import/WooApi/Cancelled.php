<?php

namespace Pine\Commerce\Import\WooApi;

use RuntimeException;

/** Thrown between pages when an administrator cancelled the run. */
class Cancelled extends RuntimeException {}

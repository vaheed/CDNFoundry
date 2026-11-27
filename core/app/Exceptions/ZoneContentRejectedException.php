<?php

namespace App\Exceptions;

use RuntimeException;

/** PowerDNS refused the record sets of a zone that Laravel rendered and validated: the content is the customer's, the service is up. */
class ZoneContentRejectedException extends RuntimeException {}

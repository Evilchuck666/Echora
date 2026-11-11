<?php

namespace App\Security;

/**
 * A change to the accounts that can't be made as things stand (409): setting up a server that already has accounts,
 * leaving it without an administrator, an administrator deleting their own account.
 */
final class AccountConflict extends \DomainException
{
}

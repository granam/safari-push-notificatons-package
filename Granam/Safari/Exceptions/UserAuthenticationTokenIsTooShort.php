<?php
declare(strict_types=1); // on PHP 7+ are standard PHP methods strict to types of given parameters

namespace Granam\Safari\Exceptions;

class UserAuthenticationTokenIsTooShort extends \LogicException implements Logic
{

}
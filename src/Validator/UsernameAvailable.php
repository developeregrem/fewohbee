<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Validator;

//use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

class UsernameAvailable extends Constraint
{
    public $message = 'form.username.na';

    //#[HasNamedArguments]
    public function __construct(public string $oldUsername = '', array $groups = null, mixed $payload = null)
    {
        parent::__construct([], $groups, $payload);
    }
}
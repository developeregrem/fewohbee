<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Interfaces;

use App\Entity\Template;

interface ITemplateRenderer
{
    /**
     * Every Service can tell which information are provided to render a template.
     */
    public function getRenderParams(Template $template, mixed $param);
}

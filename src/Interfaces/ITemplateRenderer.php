<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <alex.pensionsverwaltung@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Interfaces;

interface ITemplateRenderer {
    
    /**
     * Every Service can tell which information are provided to render a template
     * @param \App\Entity\\Template $template
     * @param mixed $param
     */
    public function getRenderParams($template, $param);
}

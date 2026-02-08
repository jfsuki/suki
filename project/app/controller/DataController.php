<?php
// app/controller/DataController.php

namespace App\Controller;

class DataController
{
    public function getClientes(): array
    {
        return [
            ['value' => 1, 'label' => 'Cliente A'],
            ['value' => 2, 'label' => 'Cliente B'],
            ['value' => 3, 'label' => 'Cliente C'],
        ];
    }
}

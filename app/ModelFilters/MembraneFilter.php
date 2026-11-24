<?php

namespace App\ModelFilters;

use EloquentFilter\ModelFilter;

class MembraneFilter extends ModelFilter
{
    public function query($name)
    {
        if (! is_string($name)) {
            return $this;
        }

        return $this->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($name).'%'])
            ->orWhereRaw('LOWER(abbreviation) LIKE ?', ['%'.strtolower($name).'%'])
            ->distinct();
    }

    public function category($id)
    {
        if (! FilterInput::isId($id)) {
            return $this->whereRaw('1 = 0');
        }

        return $this->whereHas('categories', fn ($q) => $q->where('categories.id', $id));
    }

    public function setup()
    {
        $this->defaultOrder();
    }

    public function defaultOrder()
    {
        $this->orderBy('id', 'asc');
    }
}

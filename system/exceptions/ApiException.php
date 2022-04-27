<?php

class ApiException extends MmdbException
{
    protected $level = ERROR_LVL_CRITICAL;
}
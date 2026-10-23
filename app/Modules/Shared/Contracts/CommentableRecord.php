<?php

namespace App\Modules\Shared\Contracts;

/**
 * Marks a record that can carry comments. Implemented by every TenantModel (through HasComments,
 * which also provides the `comments()` relation); the Comments component checks for it so only
 * records that opted in can be commented on.
 */
interface CommentableRecord {}

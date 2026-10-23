<?php

use App\Modules\Shared\Http\Livewire\Comments;
use App\Modules\Shared\Models\Comment;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->company = makeCompany('ACME');
    $this->ahd = makeLocation($this->company, 'AHD');
    $this->item = makeDocument($this->company, $this->ahd, ['name' => 'Brass valve']);
    $this->props = ['type' => 'test_document', 'id' => $this->item->id];
});

it('lets an Operations user post a comment on an item they cannot edit', function () {
    $ops = makeUser(['Operations User'], [$this->company]);

    Livewire::actingAs($ops)->test(Comments::class, $this->props)
        ->assertSee('Add a comment')
        ->set('body', 'Please add the thread size.')
        ->call('add')
        ->assertHasNoErrors()
        ->assertSee('Please add the thread size.')
        ->assertSet('body', '');

    $comment = Comment::withoutGlobalScopes()->sole();
    expect($comment->user_id)->toBe($ops->id)
        ->and($comment->company_id)->toBe($this->company->id)
        ->and($comment->commentable_type)->toBe('test_document')
        ->and($comment->commentable_id)->toBe($this->item->id);
});

it('keeps the record itself untouched by a comment', function () {
    $ops = makeUser(['Operations User'], [$this->company]);
    $before = $this->item->fresh()->only(['name', 'approval_status', 'updated_at']);

    Livewire::actingAs($ops)->test(Comments::class, $this->props)->set('body', 'FYI')->call('add');

    expect($this->item->fresh()->only(['name', 'approval_status', 'updated_at']))->toEqual($before);
});

it('shows a read-only notice to a user who cannot comment', function () {
    Role::create(['name' => 'Viewer only'])->givePermissionTo('item.view');
    $viewer = makeUser(['Viewer only'], [$this->company]);

    Livewire::actingAs($viewer)->test(Comments::class, $this->props)
        ->assertSee('do not have permission')
        ->assertDontSee('Post comment');
});

it('refuses a comment from a user without the comment permission', function () {
    Role::create(['name' => 'Viewer only'])->givePermissionTo('item.view');
    $viewer = makeUser(['Viewer only'], [$this->company]);

    Livewire::actingAs($viewer)->test(Comments::class, $this->props)
        ->set('body', 'sneaky')
        ->call('add')
        ->assertForbidden();

    expect(Comment::withoutGlobalScopes()->count())->toBe(0);
});

it('does not even open a record the user cannot view', function () {
    $nobody = makeUser([], [$this->company]);

    Livewire::actingAs($nobody)->test(Comments::class, $this->props)->assertForbidden();
});

it('does not reveal another company’s record', function () {
    $globex = makeCompany('GLOBEX');
    $foreign = makeUser(['Operations User'], [$globex]);

    Livewire::actingAs($foreign)->test(Comments::class, $this->props)->assertNotFound();
});

it('rejects a type that is not a commentable record', function () {
    $ops = makeUser(['Operations User'], [$this->company]);

    Livewire::actingAs($ops)->test(Comments::class, ['type' => 'role', 'id' => 1])->assertNotFound();
    Livewire::actingAs($ops)->test(Comments::class, ['type' => 'App\\Models\\User', 'id' => $ops->id])->assertNotFound();
});

it('validates the comment text', function () {
    $ops = makeUser(['Operations User'], [$this->company]);

    Livewire::actingAs($ops)->test(Comments::class, $this->props)
        ->set('body', '')->call('add')->assertHasErrors(['body' => 'required'])
        ->set('body', str_repeat('x', 2001))->call('add')->assertHasErrors(['body' => 'max']);
});

it('lets authors delete their own comment but nobody else’s', function () {
    $ops = makeUser(['Operations User'], [$this->company]);
    $purchase = makeUser(['Purchase User'], [$this->company]);

    Livewire::actingAs($ops)->test(Comments::class, $this->props)->set('body', 'mine')->call('add');
    $comment = Comment::withoutGlobalScopes()->sole();

    Livewire::actingAs($purchase)->test(Comments::class, $this->props)
        ->call('delete', $comment->id)->assertForbidden();
    expect(Comment::withoutGlobalScopes()->count())->toBe(1);

    Livewire::actingAs($ops)->test(Comments::class, $this->props)->call('delete', $comment->id);
    expect(Comment::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0)
        ->and(Comment::withoutGlobalScopes()->count())->toBe(1);   // soft-deleted, kept for the record
});

it('lets a Super Admin delete any comment', function () {
    $ops = makeUser(['Operations User'], [$this->company]);
    $admin = makeUser(['Super Admin'], [$this->company]);

    Livewire::actingAs($ops)->test(Comments::class, $this->props)->set('body', 'x')->call('add');
    $comment = Comment::withoutGlobalScopes()->sole();

    Livewire::actingAs($admin)->test(Comments::class, $this->props)->call('delete', $comment->id);

    expect(Comment::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
});

it('cannot be pointed at another record from the browser', function () {
    $ops = makeUser(['Operations User'], [$this->company]);
    $other = makeDocument($this->company, $this->ahd, ['name' => 'Other']);

    Livewire::actingAs($ops)->test(Comments::class, $this->props)
        ->set('id', $other->id);   // locked property
})->throws(CannotUpdateLockedPropertyException::class);

it('lists comments newest first with their author', function () {
    $ops = makeUser(['Operations User'], [$this->company], attributes: ['name' => 'Omkar Ops']);
    $purchase = makeUser(['Purchase User'], [$this->company], attributes: ['name' => 'Pooja Purchase']);

    Livewire::actingAs($ops)->test(Comments::class, $this->props)->set('body', 'first')->call('add');
    $this->travel(5)->minutes();
    Livewire::actingAs($purchase)->test(Comments::class, $this->props)->set('body', 'second')->call('add')
        ->assertSeeInOrder(['second', 'first'])
        ->assertSee('Pooja Purchase')
        ->assertSee('Omkar Ops');
});

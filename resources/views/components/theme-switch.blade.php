{{--
    Appearance switch.

    A three-state cycle — System → Light → Dark → System — rather than a toggle.
    A toggle can only express "on" and "off", and the state most people want is
    neither: they want the application to stop having an opinion and follow the
    device, which is what the default is and what a boolean silently destroys the
    first time it is pressed.

    Deliberately one button rather than a dropdown. It sits in a navigation bar
    whose job is to get out of the way; a popover with three radio buttons in it
    costs a click and a reflow to answer a question with three answers, and the
    current state is visible either way.

    The icon shows what is *active* (moon while dark, a monitor while following
    the device) and the accessible name says what the *press will do*, because
    those are the two different things a user needs from a control like this.

    Every value it acts on is read from the attributes the layout wrote on
    <html>, so the switch cannot disagree with what the server rendered. The
    states themselves are not listed here either — `data-theme-modes` carries
    them from config/theme.php, so adding a fourth would be a config change and
    nothing else.
--}}

<div
    x-data="themeSwitch"
    x-init="init()"
    {{-- `x-cloak` is not used here: the markup is correct before Alpine boots
         (the icons are driven by CSS off `data-theme`), and hiding it would
         make the control flicker in and out on every page load. --}}
    class="relative"
>
    <button
        type="button"
        @click="cycle()"
        class="btn btn-ghost btn-icon relative"
        :title="'Appearance: ' + label() + ' — press for ' + next().charAt(0).toUpperCase() + next().slice(1)"
        :aria-label="'Appearance: ' + label() + '. Press to switch to ' + next() + '.'"
        data-testid="theme-switch"
    >
        {{--
            Three stacked glyphs, one visible. Which one is *not* decided by
            Alpine — it is decided by CSS off `data-theme` and
            `data-requested-theme` on <html>, so the correct icon is already on
            screen at first paint instead of being added once the JavaScript
            bundle has parsed.
        --}}
        <span class="theme-icon theme-icon--sun"><x-icon name="sun" class="h-5 w-5" /></span>
        <span class="theme-icon theme-icon--moon"><x-icon name="moon" class="h-5 w-5" /></span>
        <span class="theme-icon theme-icon--system"><x-icon name="computer-desktop" class="h-5 w-5" /></span>
    </button>

    {{-- Announced politely on change, so a screen-reader user is told the theme
         moved rather than having to go and check. --}}
    <span class="sr-only" role="status" aria-live="polite"
          x-text="'Appearance: ' + label()"></span>
</div>

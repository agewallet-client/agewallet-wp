Use this guide to customize the appearance of the AgeWallet™ age gate and the Strict Mode loading screen.

For most customizations, the colour and radius controls in Step 3: Gate Appearance are sufficient — they map directly to the CSS custom properties listed in the next subsection.

For anything beyond that (custom fonts, site-wide rules, advanced selectors, hover states not exposed in the form), add your rules in **Appearance → Customize → Additional CSS**. AgeWallet applies that CSS to the gate on both Standard and Strict modes. The gate exposes a stable DOM with the `.aw-gate__*` and `.agewallet-*` class names below — write rules against them as you would for any theme component.

= CSS custom properties (set by the Gate Appearance form) =

The plugin's gate stylesheet uses these CSS variables; the Gate Appearance pickers write to them. You can also override them yourself in Customizer Additional CSS:

* `--aw-bg` — Overlay backdrop (Standard mode) and skeleton background (Strict mode).
* `--aw-card` — Card background.
* `--aw-card-border` — Card border colour.
* `--aw-text` — Card body text colour.
* `--aw-muted` — Disclaimer text colour.
* `--aw-purple` — "I Agree" button background.
* `--aw-purple-700` — "I Agree" hover state.
* `--aw-no-btn-dark-bg` — "I Disagree" button background.
* `--aw-no-btn-dark-text` — "I Disagree" button text.
* `--aw-radius` — Card border radius (px).
* `--aw-btn-radius` — Button border radius (px).

= 1. Overlay & Layout =

* `.aw-gate__overlay` - The full-screen overlay background.
Controls background color, opacity, and positioning of the age gate.

* `.aw-gate__card` - The main container (card) holding all gate content.
Controls background color, border radius, padding, and max-width.

= 2. Loading Screen (Strict Mode) =

* `.aw-skeleton-card` - The card container specifically during the "Verifying..." loading state.
Useful for adjusting the minimum height or layout during the initial check.

* `.aw-spinner` - The loading spinner animation.
Controls the size, border color, and speed of the spinner.

* `body.agewallet-strict-loading` - The class applied to the body while the skeleton is active.
Useful for hiding other page elements while verification is in progress.

= 3. Logo Area =

* `.aw-gate__logo-wrap` - Wrapper for the AgeWallet logo area.
Useful for centering or adjusting logo spacing.

* `.aw-gate__logo` - The logo image itself.
Controls image size, margin, and alignment.

= 4. Text Content =

* `.aw-gate__title` - The headline text ("You Must Verify Your Age").
Controls font size, color, weight, and margin.

* `.aw-gate__desc` - The main description paragraph under the title.
Controls text color, font size, and line height.

= 5. Buttons =

* `.aw-gate__buttons` - Container for the "I Agree" and "I Disagree" buttons.
Use this to control button spacing or layout (flex/grid, gap, alignment).

* `.aw-gate__btn--yes` - "I Agree" button.
Controls background color, hover state, border, and text color.

* `.aw-gate__btn--no` - "I Disagree" button.
Controls background color, hover state, border, and text color.

= 6. Error & Disclaimer =

* `.aw-gate__error` - Error message shown when the user doesn't meet requirements.
Controls text color, font size, and margin.

* `.aw-gate__disclaimer` - Disclaimer or fine print text at the bottom.
Controls text color, font size, and spacing.

= 7. Shortcode Wrappers =

* `.agewallet-protected-wrapper` - The main container for protected shortcode content.
Useful for adding margins.

* `.agewallet-protected-placeholder` - The placeholder box shown to unverified users, contains the gate prompt.
Controls borders, background, and padding.

= Customization Tips =

For colour and radius tweaks, the Gate Appearance form is the easiest path. For anything else, paste your CSS into **Appearance → Customize → Additional CSS**:

`
.aw-gate__btn--yes {
    background-color: #28a745 !important;
    color: #fff !important;
}
`

Customizer CSS targets the gate's `.aw-gate__*` and `.agewallet-*` classes in both Standard and Strict modes — no plugin setting required.

Use your browser's developer tools (Inspect Element) to preview your changes live.

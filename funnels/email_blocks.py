"""FluentCRM email blocks in the same markup as GravityKit's onboarding emails.

FluentCRM's visual editor stores emails as WordPress block markup and styles those blocks when it
sends. Plain HTML gets none of that styling (no padding, no header), so every cart email is built
from these helpers instead.
"""
import html

LOGO_URL = "https://www.gravitykit.com/wp-content/uploads/2026/02/logo-5.png"
LOGO_ID = 881668
BUTTON_COLOR = "#1877f2"


def header():
    """The logo on the brand-color band, as the onboarding emails open."""
    return (
        '<!-- wp:group {"backgroundColor":"primary","layout":{"type":"constrained"}} -->\n'
        '<div class="wp-block-group has-primary-background-color has-background">'
        '<!-- wp:image {"lightbox":{"enabled":false},"id":%d,"width":"200px","sizeSlug":"full",'
        '"linkDestination":"custom","align":"center"} -->\n'
        '<figure class="wp-block-image aligncenter size-full is-resized"><a href="https://www.gravitykit.com">'
        '<img src="%s" alt="GravityKit" class="wp-image-%d" style="width:200px"/></a></figure>\n'
        "<!-- /wp:image --></div>\n"
        "<!-- /wp:group -->\n\n" % (LOGO_ID, LOGO_URL, LOGO_ID)
    )


def greeting(text):
    """The first line, one size up."""
    return (
        '<!-- wp:paragraph {"fontSize":"filter-md"} -->\n'
        '<p class="has-filter-md-font-size">' + text + "</p>\n"
        "<!-- /wp:paragraph -->\n\n"
    )


def para(text):
    return "<!-- wp:paragraph -->\n<p>" + text + "</p>\n<!-- /wp:paragraph -->\n\n"


def bullets(items):
    inner = "".join(
        "<!-- wp:list-item -->\n<li>" + item + "</li>\n<!-- /wp:list-item -->\n\n" for item in items
    ).rstrip("\n")
    return '<!-- wp:list -->\n<ul class="wp-block-list">' + inner + "</ul>\n<!-- /wp:list -->\n\n"


def spacer(height=10):
    return (
        '<!-- wp:spacer {"height":"%dpx"} -->\n'
        '<div style="height:%dpx" aria-hidden="true" class="wp-block-spacer"></div>\n'
        "<!-- /wp:spacer -->\n\n" % (height, height)
    )


def button(label, href):
    """A centered button, framed by spacers, as in the onboarding emails."""
    return (
        spacer()
        + '<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->\n'
        '<div class="wp-block-buttons"><!-- wp:button {"style":{"color":{"background":"' + BUTTON_COLOR + '"}}} -->\n'
        '<div class="wp-block-button"><a class="wp-block-button__link has-background wp-element-button" href="'
        + html.escape(href, quote=True) + '" style="background-color:' + BUTTON_COLOR + '">' + label + "</a></div>\n"
        "<!-- /wp:button --></div>\n"
        "<!-- /wp:buttons -->\n\n"
        + spacer()
    )


def raw(markup):
    """Markup the block editor has no block for, such as the cart items table smart code."""
    return "<!-- wp:html -->\n" + markup + "\n<!-- /wp:html -->\n\n"


def signature(lines):
    return para("<br>".join(lines))


def stop_line(href):
    """Last line, below a light rule, linking to the cart's "Stop these emails" page (not the full unsubscribe).

    Raw HTML, because FluentCRM rebuilds paragraph and separator blocks with its own margins. The
    template already pads the bottom of the body, so the line itself has no bottom margin.
    """
    return raw(
        '<hr style="border:0;border-top:1px solid #e5e7eb;margin:24px 0 14px;">'
        '<p style="margin:0;text-align:center;color:#4b5563;font-size:14px;line-height:1.5;">'
        'Don’t want these reminders? <a href="' + href + '">Stop these emails</a>.</p>'
    )

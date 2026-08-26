# Tessa Mobile Launch Blueprint

## Purpose

Launch Tessa as North Macedonia's professional hair destination: a useful place
for clients to find the right routine and a fast daily ordering tool for salons
and stylists. The site must feel connected to the education, transformations,
extensions, and professional expertise already visible through Tessa Beauty
Institute.

This is a product and content direction for the next release. It uses existing
capabilities where possible and deliberately avoids treating the current staging
homepage as the final visual direction.

## Product Position

Tessa has two connected audiences:

| Audience | Core job | Primary promise |
| --- | --- | --- |
| Client | Find a trustworthy routine and buy it easily from a phone. | Professional hair results made understandable. |
| Stylist or salon | Reorder working products quickly and choose compatible technical products safely. | Your professional supply desk, always in your pocket. |

The storefront should not lead with internal product classes such as
"Activator" or "Fluid". Those remain precise filters and professional tools.
The customer-facing language should lead with a desired outcome.

## Mobile Storefront

### Home

1. **Campaign hero**: a real result or education-led story, not a generic
   wellness gradient. Example: "Cool blonde without the brass" with a current
   Fanola routine and a before/after image.
2. **Shop by result**: six large visual entry points: Blonde and Tone, Repair,
   Curls, Smooth and Anti-frizz, Colour, Extensions and Tools.
3. **Current brand story**: one rotating brand module at a time. It must use
   approved, current packaging and campaign imagery for Fanola, Oro Therapy,
   or RR Line.
4. **Routine builder**: retain the hair quiz, but show its practical outcome:
   shampoo, treatment, leave-in, and finish.
5. **Real proof**: transformations, educator clips, and salon work sourced
   from Tessa Beauty Institute with a product/protocol link.
6. **Professional entry**: a clear card for stylists and salons with the value
   proposition, not a small footer-like banner.
7. **Trust and delivery**: authentic products, cash on delivery, delivery
   terms, real contact details, and working legal pages.

### Shop

The default shop should start with the result paths above. A secondary filter
sheet can expose brand, hair need, line, stock, price, and professional-only
items. Keep the technical categories available but out of the first decision.

The collection header should make its context clear, for example:
"Repair damaged hair" followed by a short explanation and its routine steps.

### Product Detail

Every product page needs a stable visual structure:

1. Current transparent packshot and optional real-hair result.
2. What it solves, who it is for, and when to use it.
3. How to use it in a short, scannable sequence.
4. Complete the routine: compatible maintenance products.
5. For technical products: a stylist-only protocol panel with compatible
   activator/developer, ratio and timing only after manufacturer validation.
6. Related products from the same result or line, rather than arbitrary cards.

## Professional Workspace

The existing quick-order, stylist dashboard, orders, and invitation flows are
the foundation. The release should make them one coherent daily workflow:

1. **Today**: greeting, open cart, low-stock/saved-list reminder, and last
   order.
2. **Order again**: a one-tap row from the stylist's last purchases.
3. **Quick order**: search by product name, brand or internal product class;
   quantity steppers; stock shown before adding.
4. **Salon lists**: saved lists such as "Blonde service", "Backbar refill",
   and "Retail aftercare". Start with manually curated lists; do not block
   launch on complex automation.
5. **Technical pairing**: colour, bleach, peroxide and activator products
   should warn or guide rather than allow ambiguous combinations.
6. **Education**: short protocol cards and linked videos for new lines.
7. **Account**: professional price visibility, past orders, and support.

The protected route must preserve the requested destination through login so a
stylist who opens quick order returns to quick order after authenticating.

## Catalogue Model

Add these customer-facing collections, backed by tags rather than replacing
the existing technical categories:

| Collection | Typical product roles | Existing technical categories that may support it |
| --- | --- | --- |
| Blonde and Tone | Purple shampoo, mask, toner, bleach, aftercare | Shampoo, Mask, Hair Color, Bleach and De Color |
| Repair | Bond repair, reconstructing mask, leave-in, heat protectant | Shampoo, Mask, Filler, Spray, Fluid |
| Curls | Cleanser, mask, defining cream, refreshing spray | Shampoo, Mask, Styling, Spray |
| Smooth and Anti-frizz | Smoothing fluid, thermo protector, gloss, finishing spray | Fluid, Spray, Styling |
| Colour | Salon colour, correct developer, activator, technical treatment | Hair Color, Hydrogen Peroxide, Activator, Bleach and De Color |
| Extensions and Tools | Extensions, brushes, application/care products | New: Extensions, Brushes and Tools |

`Fluid` remains valid internal vocabulary but should be surfaced as its benefit:
"Smoothing fluid", "Repair leave-in", "Glossing fluid", or "Scalp fluid".

## Pairing Rules

Existing logic already classifies technical products as stylist-only and
recognizes Oro Therapy Gold activator versus standard Fanola systems. Expand
the presentation before expanding the rules:

| Situation | Display to stylist | Safe release behavior |
| --- | --- | --- |
| Oro Therapy colour | Dedicated Oro Therapy Gold Activator | Show the pair prominently; do not suggest standard peroxide as a substitute. |
| Fanola colour or bleach | Relevant Fanola Oxy/developer | Show a verified pair and link to the official protocol. |
| Tonal maintenance | No Yellow/No Orange plus maintenance product | Present as a home routine with frequency and caution copy. |
| Repair service | Service step plus take-home repair | Cross-sell maintenance only when the line/protocol is verified. |

Do not publish ratios, processing times, or interchangeability claims until
they are checked against the current manufacturer technical sheet.

## Images and Content

### Packshots

The present upload normalization creates a fixed pink canvas. Replace that
output strategy with a source-preserving image pipeline:

1. Keep the licensed original unchanged.
2. Create a high-resolution transparent master packshot (PNG/WebP with alpha).
3. Produce derived card, product-detail and quick-order crops from the master.
4. Use neutral or brand-specific layouts in the UI; do not burn one pink
   background into every image.
5. Route uncertain images to review instead of automatically removing a
   background around reflective gold bottles, transparent packaging or hands.

### Required Asset Types

| Asset | Use |
| --- | --- |
| Current manufacturer packshot | Product card, search, quick order |
| Current line campaign image | Home campaign and collection header |
| Real hair result | Education, collection proof, product detail |
| Short vertical educator clip | Instagram, collection story, product protocol |
| Extension and tools imagery | New extension/tool collection and product detail |

Only use manufacturer assets for which Tessa has distributor or campaign-use
permission. Store the source, owner, revision/year and approval status with
each asset.

## Asset and Product Intake Sheet

Before design implementation, fill one row for every product or asset gap.

| Priority | Brand/collection | Missing item or asset | Product status | Image source | Current packaging verified | Pairing/protocol verified | Owner | Release decision |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| P0 | Oro Therapy | Current colour and Gold line packshots | Audit | Manufacturer/distributor | No | N/A | Tessa | Block campaign until verified |
| P0 | RR Line | Current line packshots and styling imagery | Audit | Manufacturer/distributor | No | N/A | Tessa | Block campaign until verified |
| P0 | Fanola | Current No Yellow, Fiber Fix and Wonder line assets | Partial | Manufacturer/distributor | No | No | Tessa | Verify before launch |
| P0 | Extensions | Full sellable range, shades, lengths and care items | Missing | Tessa/brand | N/A | N/A | Tessa | Add products and assets |
| P0 | Tools | Brushes and hair tools with product data | Missing | Tessa/brand | N/A | N/A | Tessa | Add products and assets |
| P1 | Fluids | Rename and classify each fluid by benefit | Audit | Existing catalogue | N/A | N/A | Tessa | Map to collections |
| P1 | Education | Product-linked before/after and protocol clips | Partial | Tessa Beauty Institute | N/A | Yes | Tessa | Curate first six stories |

## Release Sequence

### Release 0: Trust and Production Parity

- Deploy the working staging routes to production.
- Fix legal routes, real contact data and professional landing availability.
- Preserve login destination and ensure primary mobile controls meet touch
  targets.

### Release 1: Professional Supply Desk

- Refine stylist workspace and quick order around repeat orders, stock and
  technical pairing.
- Import a small, reviewed group of qualified stylists from Accent Collab.
- Launch a clear invitation and activation path.

### Release 2: Visual Storefront

- Ship the new mobile home, result-led collections and product-detail routine
  panels.
- Launch with only verified current images and a deliberately limited group of
  campaigns.

### Release 3: Catalogue Expansion

- Add extensions, brushes/tools, verified fluids and missing retail products.
- Add saved salon lists and additional education/protocol content.

## Definition of Ready

Do not begin the visual storefront build until each launch collection has:

- A named owner and approved current product list.
- At least one licensed campaign image and current packshots.
- Clear consumer copy in supported languages.
- Verified stock and price data.
- Verified professional pairing information where applicable.

## First Build Slice

Build this first because it serves both audiences and keeps the release
reversible:

1. Production-parity fixes.
2. A result-led mobile home shell with approved placeholder slots, not
   invented product photography.
3. New collection/tag data model and a single fully verified campaign:
   Fanola Blonde and Tone.
4. Stylist quick-order improvement: order again, stock state and technical
   pairing presentation.
5. A product/asset intake admin workflow or import format, so content can be
   added correctly without code changes.

# Methodology: what the forecasts are, and what they are not

This document explains the reasoning behind the forecasting side of Beenchmarkracy: why it
is built on the grassland temperature sum and user-defined rules rather than on a fitted
model, why the app ships **without** plant-specific thresholds, and what you have to do
yourself before the markers mean anything for your apiary.

## 1. The grassland temperature sum (GTS)

The *Grünlandtemperatursumme* is the classic phenological clock of Central European
agrometeorology. Starting on 1 January, the daily mean temperatures above 0 °C are added
up, weighted by month:

| Month          | Weight |
|----------------|--------|
| January        | 0.5    |
| February       | 0.75   |
| March onwards  | 1.0    |

Negative days contribute nothing. The weights reflect that warmth in deep winter matters
less for plant development than the same warmth in spring. The sum grows monotonically
through the year; a value of about 200 is traditionally taken as the start of the
vegetation period (sustained grass growth), which is why beekeepers use it as a proxy for
the first larger nectar and pollen sources.

The app computes the GTS from Open-Meteo reanalysis data (ERA5 / ERA5-Land, roughly 9 km
grid) for the past, and from the 16-day forecast for the days ahead. Every day in the
calendar therefore carries the cumulative sum, and every marker is a question of the
form "on which day does the sum cross X, and is condition Y met on that day?"

## 2. Why phenology cannot be shipped as fixed numbers

The GTS is a good clock, but the dial is different everywhere. The same plant reaches the
same developmental stage at different sums depending on:

- **Site.** Altitude, exposure (a south-facing slope versus a valley floor), cold-air
  pooling, distance to water, soil type and soil moisture. Two apiaries 15 km apart can
  differ by two weeks in the onset of the same bloom.
- **Plant species and variety.** Hazel, willow, dandelion, fruit trees, rape, robinia and
  lime each respond to a different mix of temperature sum, day length and, for some,
  a chilling requirement in winter. Cultivated crops (rape, orchards) additionally depend
  on the variety the farmer chose and on the sowing or pruning regime.
- **Weather pattern of the year.** A warm February followed by a cold March produces a
  different bloom sequence than a steady spring with the same total sum. Late frost can
  destroy a bloom the sum "predicted" correctly.
- **Honeydew flows.** Forest honey (fir, spruce, oak, lime honeydew) is driven by aphid
  and scale-insect populations, which depend on the previous year's weather, winter
  mortality and predators. Temperature sums alone do not predict it; the app offers
  indicators (dry spells, warm humid periods, frost days in winter) but no date.

## 3. What you have to do: calibrate your own markers

The app is a measuring instrument; the scale has to be set by the user. For every marker
you care about, establish your own threshold from local evidence:

1. **Your own observations.** Note the date you actually saw the first hazel catkins,
   the first dandelion field, the first rape flowers, the first robinia bloom. Open that
   day in the calendar: the GTS shown there is your site-specific value for that event.
   Repeat for every year you have notes; the app keeps 11 years of weather history, so old
   diaries are worth entering. Use the average, and keep the spread in mind.
2. **Regional phenological networks.** National weather services run observer networks
   that publish first-bloom dates for standard plants by region and year (in the
   German-speaking countries: the DWD phenology network in Germany, the GeoSphere Austria
   phenology programme, MeteoSwiss in Switzerland; Europe-wide the PEP725 database).
   Take the observation station closest to your apiary and similar in altitude and
   exposure, convert its dates to GTS values with the calendar, and compare with your own.
3. **Studies and regional beekeeping literature.** Bee institutes, beekeeping associations
   and agricultural extension services publish flowering calendars and temperature-sum
   tables for their region. Treat them as a starting range, not as a value.
4. **Neighbouring beekeepers.** Local knowledge about which slope blooms first, when the
   rape usually opens, and which forest gave honeydew in which years is often better than
   any dataset.

Once you have a threshold, enter it as a marker and use the **historical preview** in the
rule builder: it shows on which date the rule would have triggered in each of the past
years. If those dates scatter by four weeks, the rule is not sharp enough for your site
and needs an additional condition (a minimum daily maximum, a day count, a dry spell) or
a different threshold. If they cluster within a few days, the marker is usable.

Repeat the check every year. Thresholds drift as the climate does, and a marker that fit
the 2010s may run early in the 2020s.

## 4. Reference periods used by the app

Two different look-back windows are used, deliberately:

| Purpose | Window | Why |
|---|---|---|
| Historical GTS curve, rule preview, "percent of average" rules | rolling last 11 complete years | recent enough to reflect the current climate, long enough to smooth single years |
| Monthly temperature and precipitation deviations in the calendar | fixed 30-year climate normals 1995–2024 | the conventional climatological reference, comparable across sites and years |

When a rule compares a year against the historical average in percent, that year is
excluded from its own reference, so an unusual year does not dampen its own signal.

## 5. Limitations you should keep in mind

- **Grid data, not your thermometer.** Open-Meteo's reanalysis represents a grid cell of
  several kilometres. Frost pockets, urban heat and slope effects are not resolved. If
  you have a station on site, expect a systematic offset; calibrate the thresholds
  against the app's numbers, not against your thermometer.
- **Forecast horizon.** The 16-day forecast is reliable for a few days and increasingly
  uncertain beyond a week. A marker "in 12 days" means "roughly in two weeks", not a
  date to plan a harvest around.
- **No confidence intervals.** The app shows a single date, not a range. The range is in
  your calibration data (the year-to-year spread of the historical preview); look at it.
- **No plant model.** The app does not know what grows around your apiary. It knows
  temperatures and rain. Everything botanical is encoded in the thresholds you set.

## 6. What was deliberately left out, and why

A built-in accuracy score ("this marker was right ± 3 days in 8 of 11 years") was
considered and not shipped. Such a score is only meaningful against ground truth, and
the ground truth is your observed bloom dates, which live in your notes and not in the
database. Displaying a score computed against the app's own past GTS curve would be
circular and would suggest a precision the method cannot deliver across sites and
plants. The historical preview gives you the same information honestly: the raw dates
per year, for you to judge.

The same applies to fitted models (regression, machine learning) for bloom dates. With
one site and roughly a decade of data per plant there is nothing robust to train on, and
a model trained on one region does not transfer to another. Rules that the beekeeper
understands, sets and adjusts are the better tool for the typical apiary: they are
transparent, they fail visibly, and they improve with every year of observation.

If you want to go further for your own site, the data is all there: export the weather
history, add your observed dates, and fit whatever you like. The app's job is to make
the day-to-day decision easier, not to replace the beekeeper's judgement.

"""Domain pack: a team watching the stock market.

Same shape as any pack (field, kind, text, tags). Educational / monitoring
knowledge — not financial advice. Switch to it with BRAIN_DOMAIN=stockmarket.
Shows the engine is domain-agnostic: the SEO fields disappear and a whole
different set of skills takes their place.
"""

FIELDS = [
    "technical", "fundamentals", "macro", "sentiment", "risk",
    "options", "crypto", "earnings", "quant",
]

CORPUS = [
    # ---- technical analysis -----------------------------------------
    ("technical", "fact", "Trend is context: price above a rising 200-day average is a structurally different regime than below it", ["trend"]),
    ("technical", "fact", "Volume confirms moves — a breakout on weak volume is suspect", ["volume", "breakout"]),
    ("technical", "fact", "Support and resistance are zones, not exact lines; watch how price reacts around them", ["levels"]),
    ("technical", "fact", "Divergence between price and momentum (RSI/MACD) often precedes a turn", ["momentum", "divergence"]),
    ("technical", "fact", "Higher timeframes dominate lower ones — align intraday signals with the daily/weekly trend", ["timeframe"]),
    ("technical", "training", "Multi-timeframe check: mark weekly trend, daily structure, then time entries on the hourly", ["process"]),
    ("technical", "tool", "TradingView — charting, alerts and screeners across markets", ["tool", "charting"]),

    # ---- fundamentals -----------------------------------------------
    ("fundamentals", "fact", "Valuation is relative: compare P/E, EV/EBITDA and growth to peers and the company's own history", ["valuation"]),
    ("fundamentals", "fact", "Free cash flow is harder to fake than earnings — watch cash conversion", ["cashflow"]),
    ("fundamentals", "fact", "A wide, durable moat lets a business compound; commodity businesses can't", ["moat", "quality"]),
    ("fundamentals", "fact", "Rising debt with falling interest coverage is a red flag in a high-rate world", ["balance-sheet", "debt"]),
    ("fundamentals", "tool", "SEC EDGAR — primary-source 10-K/10-Q filings for the real numbers", ["tool", "filings"]),

    # ---- macro ------------------------------------------------------
    ("macro", "fact", "Rates drive valuations: higher discount rates compress the multiple on long-duration growth stocks", ["rates", "valuation"]),
    ("macro", "fact", "The yield curve inverting has preceded most recessions — but with long, variable lags", ["yield-curve", "recession"]),
    ("macro", "fact", "Watch the policy path (central bank guidance), not just the current rate", ["central-bank", "policy"]),
    ("macro", "fact", "A strong dollar tightens global financial conditions and pressures commodities and EM", ["dollar", "liquidity"]),
    ("macro", "training", "Macro dashboard: track rates, inflation prints, jobs, PMIs and central-bank meetings on one calendar", ["process", "calendar"]),
    ("macro", "tool", "FRED (St. Louis Fed) — free, authoritative macro time series", ["tool", "data"]),

    # ---- sentiment / news -------------------------------------------
    ("sentiment", "fact", "Positioning matters: extreme bullishness or bearishness often marks turning points", ["positioning", "contrarian"]),
    ("sentiment", "fact", "Price reaction to news beats the news itself — a rally on bad news signals strength", ["reaction"]),
    ("sentiment", "fact", "The VIX rises with fear; sustained spikes often accompany capitulation lows", ["vix", "fear"]),
    ("sentiment", "training", "News triage: separate signal (guidance, regulation, rates) from noise (recycled headlines)", ["process"]),
    ("sentiment", "tool", "An economic + earnings calendar to anticipate volatility events", ["tool", "calendar"]),

    # ---- risk management --------------------------------------------
    ("risk", "fact", "Position size off risk, not conviction: risk a fixed small % of capital per idea", ["position-sizing"]),
    ("risk", "fact", "Define the invalidation level before entering — where the thesis is simply wrong", ["stop", "invalidation"]),
    ("risk", "fact", "Correlated positions are one position — diversify across drivers, not just tickers", ["correlation", "diversification"]),
    ("risk", "fact", "Survive first: a 50% drawdown needs a 100% gain to recover", ["drawdown"]),
    ("risk", "training", "Pre-trade checklist: thesis, invalidation, size, and what would make you exit", ["process", "checklist"]),

    # ---- options ----------------------------------------------------
    ("options", "fact", "Implied volatility sets the price — buying options into high IV is a headwind even if you're right on direction", ["iv", "vega"]),
    ("options", "fact", "Theta decays fastest in the final weeks; long options are a race against time", ["theta"]),
    ("options", "fact", "IV usually collapses right after earnings — the 'IV crush'", ["earnings", "iv-crush"]),
    ("options", "fact", "Defined-risk spreads cap the loss versus naked options", ["spreads", "risk"]),

    # ---- crypto -----------------------------------------------------
    ("crypto", "fact", "Crypto trades 24/7 with no circuit breakers — gaps and liquidations cascade fast", ["liquidity", "risk"]),
    ("crypto", "fact", "On-chain flows (exchange in/outflows, stablecoin supply) are a data edge equities don't have", ["on-chain"]),
    ("crypto", "fact", "Bitcoin dominance often signals risk appetite across the crypto complex", ["btc-dominance"]),

    # ---- earnings ---------------------------------------------------
    ("earnings", "fact", "The guidance and the call matter more than the headline beat/miss", ["guidance"]),
    ("earnings", "fact", "A beat that sells off means expectations were already priced in", ["expectations"]),
    ("earnings", "training", "Earnings prep: know the date, the expected move (from options), and the 2-3 metrics that matter", ["process"]),

    # ---- quant / signals --------------------------------------------
    ("quant", "fact", "Backtests overfit — insist on out-of-sample and walk-forward validation", ["backtest", "overfitting"]),
    ("quant", "fact", "Transaction costs and slippage kill many 'profitable' strategies on paper", ["costs"]),
    ("quant", "fact", "A signal needs an economic reason, or it's probably data-mined noise", ["signal"]),
    ("quant", "tool", "A data pipeline (prices, fundamentals, macro) feeding one research notebook", ["tool", "data"]),
]


def by_field(field):
    return [e for e in CORPUS if e[0] == field]


def stats():
    kinds = {}
    for _f, kind, _t, _tags in CORPUS:
        kinds[kind] = kinds.get(kind, 0) + 1
    return {"entries": len(CORPUS), "fields": len(FIELDS), "by_kind": kinds}

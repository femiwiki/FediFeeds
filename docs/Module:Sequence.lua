-- The order of the pages, read from MediaWiki:Sidebar, so it is written down once.
-- Adapted from wikven's own docs (chaotic-ground/wikven, docs/Module:Sequence.lua).
local p = {}

-- "** Page|Label"; an entry linking outside the site has "://" and is passed over
local ENTRY = '^%*%*%s*([^|\n]+)|([^\n]+)'

local function sequence()
	local content = mw.title.new('MediaWiki:Sidebar'):getContent() or ''
	local pages = {}
	for line in mw.text.gsplit(content, '\n') do
		local page, label = line:match(ENTRY)
		if page and not page:find('://', 1, true) then
			pages[#pages + 1] = { page = mw.text.trim(page), label = mw.text.trim(label) }
		end
	end
	if #pages == 0 then
		error('MediaWiki:Sidebar has no "** Page|Label" entries', 0)
	end
	return pages
end

-- A card for the page on one side: "Previous" or "Next" over the page's sidebar label
local function card(side, entry)
	local word = mw.message.new('fedifeeds-prevnext-' .. side):plain()
	return '<div class="fedifeeds-prevnext-' .. side .. '">[[' .. entry.page
		.. '|<span class="fedifeeds-prevnext-label">' .. word .. '</span>'
		.. '<span class="fedifeeds-prevnext-title">' .. entry.label .. '</span>]]</div>'
end

-- A previous link, a next link, or both; nothing on a page the sidebar does not name
function p.row(frame)
	local pages = sequence()
	local here = mw.title.getCurrentTitle().text
	local i
	for n, entry in ipairs(pages) do
		if entry.page == here then
			i = n
		end
	end
	if not i then
		return ''
	end
	local out = {}
	if pages[i - 1] then
		out[#out + 1] = card('prev', pages[i - 1])
	end
	if pages[i + 1] then
		out[#out + 1] = card('next', pages[i + 1])
	end
	return table.concat(out)
end

return p

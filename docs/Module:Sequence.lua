-- The order of the pages, read from MediaWiki:Sidebar, so it is written down once.
-- Adapted from wikven's own docs (chaotic-ground/wikven, docs/Module:Sequence.lua).
local p = {}

-- "** Special:MyLanguage/Page|key"; an entry linking outside the site does not match
local ENTRY = '^%*%*%s*Special:MyLanguage/([^|\n]+)'

local function sequence()
	local content = mw.title.new('MediaWiki:Sidebar'):getContent() or ''
	local pages = {}
	for line in mw.text.gsplit(content, '\n') do
		local page = line:match(ENTRY)
		if page then
			pages[#pages + 1] = mw.text.trim(page)
		end
	end
	if #pages == 0 then
		error('MediaWiki:Sidebar has no "** Special:MyLanguage/Page|key" entries', 0)
	end
	return pages
end

-- A translation such as "Runs/ko" stands where "Runs" does. Subpages are off in the main
-- namespace, so match the prefix rather than asking the title for its root.
local function position(pages, here)
	for i, page in ipairs(pages) do
		if here == page or here:sub(1, #page + 1) == page .. '/' then
			return i
		end
	end
	return nil
end

-- The page's title in the language being read: its translated display title, or its own name
local function label(page, lang)
	if lang ~= '' then
		local title = mw.title.new('Translations:' .. page .. '/Page display title' .. lang)
		local text = title and title:getContent()
		if text then
			return mw.text.trim(text)
		end
	end
	return page
end

-- A card for the page on one side: "Previous" or "Next" over the page's title
local function card(side, page, lang)
	local code = lang ~= '' and lang:sub(2) or mw.language.getContentLanguage():getCode()
	local word = mw.message.new('fedifeeds-prevnext-' .. side):inLanguage(code):plain()
	return '<div class="fedifeeds-prevnext-' .. side .. '">[[Special:MyLanguage/' .. page
		.. '|<span class="fedifeeds-prevnext-label">' .. word .. '</span>'
		.. '<span class="fedifeeds-prevnext-title">' .. label(page, lang) .. '</span>]]</div>'
end

-- The title of the page named by the first argument, in the language of the page calling it
function p.label(frame)
	return label(mw.text.trim(frame.args[1]), frame:preprocess('{{#translation:}}'))
end

-- A previous link, a next link, or both; nothing on a page the sidebar does not name
function p.row(frame)
	local pages = sequence()
	local i = position(pages, mw.title.getCurrentTitle().text)
	if not i then
		return ''
	end
	-- "/ko" on a Korean translation, "" on the source page
	local lang = frame:preprocess('{{#translation:}}')
	local out = {}
	if pages[i - 1] then
		out[#out + 1] = card('prev', pages[i - 1], lang)
	end
	if pages[i + 1] then
		out[#out + 1] = card('next', pages[i + 1], lang)
	end
	return table.concat(out)
end

return p

from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_LEFT, TA_CENTER
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    BaseDocTemplate,
    Frame,
    Image,
    KeepTogether,
    PageBreak,
    PageTemplate,
    Paragraph,
    Spacer,
    Table,
    TableStyle,
)


ROOT = Path(r"E:\volume_0\darkscrub")
OUT = ROOT / "output" / "pdf"
OUT.mkdir(parents=True, exist_ok=True)
PDF_PATH = OUT / "multishipping_pracovny_postup.pdf"
ASSETS = ROOT / "output" / "manual_assets"
ORDER_LIST_SCREENSHOT = ASSETS / "multishipping-order-list.png"
MULTISHIPPING_MODAL_SCREENSHOT = ASSETS / "multishipping-modal.png"
FEDEX_PREVIEW_SCREENSHOT = ASSETS / "multishipping-fedex-preview.png"

font_dir = Path(r"C:\Windows\Fonts")
pdfmetrics.registerFont(TTFont("Arial", str(font_dir / "arial.ttf")))
pdfmetrics.registerFont(TTFont("Arial-Bold", str(font_dir / "arialbd.ttf")))

TEAL = colors.HexColor("#167C80")
TEAL_LIGHT = colors.HexColor("#EAF6F6")
NAVY = colors.HexColor("#263746")
LIGHT = colors.HexColor("#F3F6F8")
BORDER = colors.HexColor("#D9D9D9")
MUTED = colors.HexColor("#5A646E")
BLACK = colors.HexColor("#111111")


class GuideDocTemplate(BaseDocTemplate):
    def __init__(self, filename, **kwargs):
        super().__init__(filename, **kwargs)
        frame = Frame(
            self.leftMargin,
            self.bottomMargin,
            self.width,
            self.height,
            id="normal",
            leftPadding=0,
            rightPadding=0,
            topPadding=0,
            bottomPadding=0,
        )
        self.addPageTemplates(PageTemplate(id="guide", frames=[frame], onPage=self.draw_footer))

    def draw_footer(self, canvas, doc):
        canvas.saveState()
        canvas.setFont("Arial", 8)
        canvas.setFillColor(MUTED)
        canvas.drawCentredString(A4[0] / 2, 9.5 * mm, f"DARKSCRUB  |  Multishipping pre expedíciu  |  Strana {doc.page}")
        canvas.restoreState()


styles = getSampleStyleSheet()
title = ParagraphStyle(
    "TitleCustom",
    fontName="Arial-Bold",
    fontSize=24,
    leading=27,
    textColor=BLACK,
    spaceAfter=5 * mm,
)
subtitle = ParagraphStyle(
    "SubtitleCustom",
    fontName="Arial-Bold",
    fontSize=12,
    leading=14,
    textColor=TEAL,
    spaceAfter=5 * mm,
)
h1 = ParagraphStyle(
    "H1Custom",
    fontName="Arial-Bold",
    fontSize=14,
    leading=17,
    textColor=BLACK,
    spaceBefore=4.3 * mm,
    spaceAfter=2.5 * mm,
    keepWithNext=True,
)
body = ParagraphStyle(
    "BodyCustom",
    fontName="Arial",
    fontSize=9.5,
    leading=12.3,
    textColor=colors.HexColor("#1E242A"),
    spaceAfter=2.1 * mm,
)
body_bold = ParagraphStyle("BodyBold", parent=body, fontName="Arial-Bold")
step = ParagraphStyle(
    "StepCustom",
    parent=body,
    leftIndent=7 * mm,
    firstLineIndent=-7 * mm,
    spaceAfter=2.2 * mm,
)
bullet = ParagraphStyle(
    "BulletCustom",
    parent=body,
    leftIndent=5.5 * mm,
    firstLineIndent=-3.7 * mm,
    bulletIndent=0,
    spaceAfter=1.7 * mm,
)
cell = ParagraphStyle("CellCustom", parent=body, fontSize=8.7, leading=10.8, spaceAfter=0)
cell_bold = ParagraphStyle("CellBold", parent=cell, fontName="Arial-Bold")
header_cell = ParagraphStyle(
    "HeaderCell",
    parent=cell,
    fontName="Arial-Bold",
    textColor=colors.white,
    alignment=TA_LEFT,
)
figure_caption = ParagraphStyle(
    "FigureCaption",
    parent=body,
    fontSize=8.4,
    leading=10.4,
    textColor=MUTED,
    alignment=TA_CENTER,
    spaceBefore=1.5 * mm,
    spaceAfter=3 * mm,
)


def P(text, style=body):
    return Paragraph(text, style)


def heading(text):
    return Paragraph(text, h1)


def numbered(n, label, text):
    return Paragraph(f'<font color="#167C80"><b>{n}.&nbsp;&nbsp;{label}</b></font>&nbsp;&nbsp;{text}', step)


def bullet_item(text):
    return Paragraph(f"•&nbsp;&nbsp;{text}", bullet)


def figure(image_path, caption, width=172 * mm):
    image = Image(str(image_path))
    aspect = image.imageHeight / image.imageWidth
    image.drawWidth = width
    image.drawHeight = width * aspect
    image.hAlign = "CENTER"
    return KeepTogether([
        image,
        Paragraph(caption, figure_caption),
    ])


def styled_table(data, widths, header_fill=NAVY, alternating=True):
    table = Table(data, colWidths=widths, repeatRows=1, hAlign="CENTER")
    commands = [
        ("BACKGROUND", (0, 0), (-1, 0), header_fill),
        ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
        ("GRID", (0, 0), (-1, -1), 0.45, BORDER),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("LEFTPADDING", (0, 0), (-1, -1), 6),
        ("RIGHTPADDING", (0, 0), (-1, -1), 6),
        ("TOPPADDING", (0, 0), (-1, -1), 5),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
    ]
    if alternating:
        for row in range(1, len(data)):
            if row % 2 == 0:
                commands.append(("BACKGROUND", (0, row), (-1, row), LIGHT))
    table.setStyle(TableStyle(commands))
    return table


story = [
    Paragraph("Multishipping hotových objednávok", title),
    Paragraph("Pracovný postup pre expedíciu", subtitle),
    P(
        "<b>Multishipping použite vtedy, keď viac hotových objednávok toho istého zákazníka balíte do jednej krabice s jedným tracking number.</b> "
        "Systém odošle do FedExu iba jednu hlavnú objednávku a po importe EOD pridelí rovnaké sledovanie všetkým objednávkam v skupine."
    ),
    heading("Postup vytvorenia multishippingu"),
    numbered(1, "Otvorte Ready for Label", "Pracujte na stránke Orders so statusom Ready for Label."),
    numbered(2, "Kliknite na tyrkysovú ikonku", "Ikonka s počtom pri mene zákazníka otvorí okno Multishipping."),
    numbered(3, "Vyberte objednávky v krabici", "Štvorčekom vľavo zaškrtnite iba objednávky, ktoré skutočne vložíte do rovnakej krabice."),
    numbered(4, "Určte hlavnú objednávku", "Kruhovým prepínačom Master napravo označte objednávku, ktorá sa použije vo FedEx CSV."),
    numbered(5, "Skontrolujte adresy", "Všetky vybrané objednávky musia patriť tomu istému zákazníkovi a musia mať správnu spoločnú doručovaciu adresu."),
    numbered(6, "Uložte skupinu", "Kliknite na Save multishipping. Objednávky zostanú v stave Ready for Label, ale systém ich označí ako jednu zásielku."),
    numbered(7, "Skontrolujte označenia", "Hlavná objednávka dostane označenie MULTI s počtom objednávok; ostatné ukážu, s ktorou hlavnou objednávkou sa odosielajú."),
    PageBreak(),
    heading("Vizuálna ukážka"),
    figure(
        ORDER_LIST_SCREENSHOT,
        "Obrázok 1&nbsp;&nbsp; Hlavná objednávka MULTI 4 a tri pridružené objednávky smerujúce k SO21490.",
    ),
    figure(
        MULTISHIPPING_MODAL_SCREENSHOT,
        "Obrázok 2&nbsp;&nbsp; Zaškrtnuté objednávky patria do jednej krabice; prepínač Master určuje riadok pre FedEx CSV.",
        width=164 * mm,
    ),
    PageBreak(),
    heading("Označenia v zozname objednávok"),
]

label_data = [
    [P("Označenie v tabuľke", header_cell), P("Význam", header_cell)],
    [P('<font color="#167C80"><b>Tyrkysová ikonka s počtom</b></font>', cell), P("Otvorí výber objednávok pre nový multishipping.", cell)],
    [P('<font color="#167C80"><b>MULTI · 3 orders</b></font>', cell), P("Hlavná objednávka skupiny. Iba táto objednávka sa odošle do FedEx CSV.", cell)],
    [P('<font color="#167C80"><b>MULTI → SO21490</b></font>', cell), P("Pridružená objednávka. Odošle sa v rovnakej krabici ako uvedená hlavná objednávka.", cell)],
]
story.extend([
    styled_table(label_data, [52 * mm, 120 * mm]),
    Spacer(1, 3 * mm),
    P("<font color=\"#167C80\"><b>Dôležité:</b></font> <b>Pred vytvorením FedEx CSV musia byť všetky plánované multishipping skupiny uložené. Toto pravidlo platí na oboch pracoviskách.</b>"),
    heading("Export do FedExu"),
])

for text in (
    "Samostatná objednávka sa zobrazí v CSV náhľade normálne.",
    "Z multishipping skupiny sa v náhľade zobrazí iba hlavná objednávka.",
    "Pridružené objednávky sa do FedEx CSV nepridajú ako samostatné zásielky.",
    "Pri hlavnom riadku systém pripraví spoločné typy produktov, hodnotu, poistenie a odhad hmotnosti celej krabice.",
    "V náhľade skontrolujte a podľa skutočnosti upravte najmä hmotnosť, colnú hodnotu, poistenie, službu a adresu.",
    "FedEx referencia musí zostať ukončená číslom hlavnej objednávky, aby ju EOD import správne našiel.",
):
    story.append(bullet_item(text))

story.append(figure(
    FEDEX_PREVIEW_SCREENSHOT,
    "Obrázok 3&nbsp;&nbsp; FedEx CSV náhľad obsahuje iba hlavnú objednávku SO21490 označenú MULTI 4 orders.",
))

story.extend([
    heading("Import EOD"),
    P("<b>Po vytvorení zásielok vo FedEx Stratus nahrajte EOD report späť cez Import EOD.</b> Keď systém nájde hlavnú objednávku, automaticky spracuje celú skupinu."),
])
for text in (
    "Rovnaké tracking number sa pridelí hlavnej aj všetkým pridruženým objednávkam.",
    "Všetky objednávky dostanú rovnaký dátum odoslania a status Shipped.",
    "Aktivita sa zapíše ku každej objednávke samostatne.",
    "Ak sa nepodarí spracovať jednu objednávku, systém vráti späť celú skupinu. Nevznikne čiastočne odoslaná zásielka.",
    "Opakovaný import rovnakého EOD nevytvorí duplicitné trackingy.",
    "Ak skupina obsahuje eBay objednávky, systém ich všetky pridá aj do eBay tracking exportu.",
):
    story.append(bullet_item(text))

story.append(heading("Stavy skupiny a opravy"))
state_data = [
    [P("Stav skupiny", header_cell), P("Čo znamená", header_cell), P("Čo môže pracovník urobiť", header_cell)],
    [P("Draft", cell_bold), P("Skupina je uložená, CSV ešte nebolo vytvorené.", cell), P("Upraviť hlavný výber, pridať alebo odobrať objednávku, prípadne skupinu zrušiť.", cell)],
    [P("Exported locked", cell_bold), P("Skupina bola vložená do FedEx CSV a je zamknutá.", cell), P("Pri chybe odomknúť skupinu, nepoužiť staré CSV a vytvoriť nové.", cell)],
    [P("Shipped", cell_bold), P("EOD import pridelil tracking a zásielku uzavrel.", cell), P("Skontrolovať tracking pri všetkých objednávkach. Skupina už nie je určená na úpravu.", cell)],
]
story.extend([
    styled_table(state_data, [32 * mm, 50 * mm, 90 * mm], header_fill=TEAL),
    heading("Najdôležitejšie pravidlá"),
])
for text in (
    "Objednávkam ručne nemeňte status kvôli členstvu v multishippingu. Členstvo je samostatná informácia.",
    "Jedna objednávka môže patriť iba do jednej multishipping skupiny.",
    "Ak systém oznámi, že objednávka už bola exportovaná, nevytvárajte druhý štítok. Najprv skontrolujte export na druhom pracovisku.",
    "Po odomknutí skupiny nepoužívajte staré FedEx CSV. Vytvorte nový export.",
    "Po importe EOD skontrolujte tracking minimálne na hlavnej a jednej pridruženej objednávke.",
):
    story.append(bullet_item(text))

doc = GuideDocTemplate(
    str(PDF_PATH),
    pagesize=A4,
    leftMargin=18 * mm,
    rightMargin=18 * mm,
    topMargin=17 * mm,
    bottomMargin=17 * mm,
    title="Multishipping hotových objednávok",
    author="DARKSCRUB",
    subject="Pracovný postup pre expedíciu",
)
doc.build(story)
print(PDF_PATH)

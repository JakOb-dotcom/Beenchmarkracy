# Varroa detection model (`weights/best.pt`)

YOLOv11-nano weights for detecting varroa mites on photos of bottom boards
(debris diagnosis). Loaded by `PythonWorkerOCR/worker.py` (`run_varroa_pipeline`).

## Origin

- Project: **VarroDetector** – https://github.com/jodivaso/VarroDetector
- Authors: Jose Divasón et al. (University of La Rioja, University of Zaragoza, BeeGuards Consortium)
- License: **GNU Affero General Public License v3.0** – full text in [LICENSE](LICENSE) (included unchanged)
- Fork containing the weights used here: https://github.com/JakOb-dotcom/VarroDetector

The weights are unmodified (`model/weights/best.pt` of the upstream repository, commit `1cbe729`, 2026-01-08).
The use has been agreed with the author: this project is fully open source and published
under the AGPL-3.0; copyright notices and the license text are preserved.

## Citation

When using the mite counting, please cite the underlying study:

> Yániz, J., Casalongue, M., Martinez-de-Pison, F. J., Silvestre, M. A., Consortium, B., Santolaria, P., & Divasón, J. (2025).
> *An AI-Based Open-Source Software for Varroa Mite Fall Analysis in Honeybee Colonies.*
> Agriculture, 15(9), 969. https://doi.org/10.3390/agriculture15090969

BibTeX: see [THIRD_PARTY_NOTICES.md](../../THIRD_PARTY_NOTICES.md).

## Maintaining the fork

The fork above is a plain GitHub fork of https://github.com/jodivaso/VarroDetector (created via the
**Fork** button on the upstream page). Any change to the weights (should the model ever be retrained)
is versioned exclusively in that fork, and the commit ID is recorded here. If the fork ever has to be
recreated, keep the name `VarroDetector` so the links in this file, `THIRD_PARTY_NOTICES.md` and the
project README stay valid.

## Inference parameters

`worker.py` uses the same settings as VarroDetector: `imgsz=6016`, `conf=0.1`, `iou=0.5`, `max_det=2000`
(CPU inference, no GPU required; smartphone photos at full resolution).

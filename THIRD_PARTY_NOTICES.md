# Third-Party Notices

Beenchmarkracy is licensed under the **GNU Affero General Public License v3.0 (AGPL-3.0)**,
see [LICENSE](LICENSE). The following third-party components are included or used.

## VarroDetector (YOLO model for varroa counting)

| | |
|---|---|
| Component | `PythonWorkerOCR/model/weights/best.pt` (YOLOv11-nano weights trained on varroa mites) |
| Origin | https://github.com/jodivaso/VarroDetector |
| Authors / copyright | © Jose Divasón and the authors of VarroDetector (University of La Rioja, University of Zaragoza, BeeGuards Consortium) |
| License | GNU Affero General Public License v3.0 – full text in [PythonWorkerOCR/model/LICENSE](PythonWorkerOCR/model/LICENSE) |
| Use in this project | mite counting on bottom-board photos in `PythonWorkerOCR/worker.py` (`run_varroa_pipeline`) |

The integration was done with the author's consent under the condition that this project
is fully open source, is published under the AGPL-3.0 (or a compatible license), and that
the original copyright notices and the license text are preserved.
The weights are additionally maintained in a GitHub fork of the original project,
https://github.com/JakOb-dotcom/VarroDetector (see [PythonWorkerOCR/model/README.md](PythonWorkerOCR/model/README.md)).

**Please cite** (scientific basis of the model):

> Yániz, J., Casalongue, M., Martinez-de-Pison, F. J., Silvestre, M. A., Consortium, B., Santolaria, P., & Divasón, J. (2025).
> *An AI-Based Open-Source Software for Varroa Mite Fall Analysis in Honeybee Colonies.*
> Agriculture, 15(9), 969. https://doi.org/10.3390/agriculture15090969

```bibtex
@article{VarroDetector,
  title     = {An AI-Based Open-Source Software for Varroa Mite Fall Analysis in Honeybee Colonies},
  volume    = {15},
  ISSN      = {2077-0472},
  url       = {http://dx.doi.org/10.3390/agriculture15090969},
  DOI       = {10.3390/agriculture15090969},
  number    = {9},
  journal   = {Agriculture},
  publisher = {MDPI AG},
  author    = {Yániz, Jesús and Casalongue, Matías and Martinez-de-Pison, Francisco Javier and Silvestre, Miguel Angel and Consortium, Beeguards and Santolaria, Pilar and Divasón, Jose},
  year      = {2025}
}
```

## Ultralytics YOLO

Runtime library for the model above (`ultralytics` Python package). License: AGPL-3.0.
https://github.com/ultralytics/ultralytics

## PaddleOCR / PaddlePaddle

Text recognition (handwriting on forms). License: Apache License 2.0.
https://github.com/PaddlePaddle/PaddleOCR

## Alpine.js

`assets/vendor/alpine.min.js`, version 3.13.3. License: MIT. © Caleb Porzio and contributors.
https://github.com/alpinejs/alpine

## Open-Meteo

Weather data is fetched at runtime from https://open-meteo.com (Open-Meteo terms of use; data under CC BY 4.0).

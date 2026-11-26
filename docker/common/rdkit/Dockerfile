FROM continuumio/miniconda3:23.10.0-1

WORKDIR /usr/src/app

RUN pip3 install --no-cache-dir requests
# The canonical SMILES stored in structures.canonical_smiles (the key that
# tells whether a molecule is already in the database) depend on the RDKit
# version. Upgrading it may change the form of some molecules: run
# `php artisan structures:convert-canonical-smiles --dry-run` with the new
# image and convert the records before new ones are written, and bump
# Modules\Rdkit\Rdkit::CANONICAL_SMILES_FORM so that no cached form of the old
# version is reused.
RUN pip3 install --no-cache-dir rdkit==2026.9.2
RUN pip3 install --no-cache-dir dimorphite-dl
# RUN pip3 install gssapi
# RUN pip3 install awscli --ignore-installed six
RUN pip3 install setuptools paramiko

COPY . .

EXPOSE 9696

CMD [ "python3", "-u", "./main.py" ]
